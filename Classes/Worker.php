<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit;

use Flowpack\JobQueue\Common\Queue\Message;
use Neos\Cache\Frontend\FrontendInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\ConsoleOutput;
use t3n\JobQueue\RabbitMQ\Queue\RabbitQueue;

/**
 * @Flow\Proxy(false)
 */
final class Worker
{
    /**
     * @var string
     */
    protected $command;

    /**
     * @var RabbitQueue
     */
    protected $queue;

    /**
     * @var FrontendInterface
     */
    protected $messageCache;

    /**
     * @var ConsoleOutput
     */
    protected $output;

    /**
     * @var Lock
     */
    private $lock;

    public function __construct(
        string $command,
        RabbitQueue $queue,
        array $queueSettings,
        FrontendInterface $messageCache,
        Lock $lock
    ) {
        $this->command = $command;
        $this->queue = $queue;
        $this->queueSettings = $queueSettings;
        $this->messageCache = $messageCache;
        $this->lock = $lock;
    }

    public function prepare()
    {
        $this->output = new ConsoleOutput();
        $this->outputLine('Watching queue <b>"%s"</b>', $this->queue->getName());
    }

    public function executeMessage(Message $message)
    {
        $messageCacheIdentifier = sha1(serialize($message));
        $this->messageCache->set($messageCacheIdentifier, $message);

        $this->lock->run(function() use (&$messageCacheIdentifier, &$commandOutput, &$result) {
            exec(
                $this->command . ' --messageCacheIdentifier=' . escapeshellarg($messageCacheIdentifier),
                $commandOutput,
                $result
            );
        });
        $this->outputLine('Memory 1: %s', memory_get_peak_usage(false));
        $this->outputLine('Memory 2: %s', memory_get_peak_usage(true));

        if ($result === 0) {
            $this->queue->finish($message->getIdentifier());
            $this->outputLine(
                '<success>Successfully executed job "%s" (%s)</success>',
                $message->getIdentifier(),
                join('', $commandOutput)
            );

        } else {
            $maximumNumberOfReleases = isset($this->queueSettings['maximumNumberOfReleases'])
                ? (int)$this->queueSettings['maximumNumberOfReleases']
                : JobManager::DEFAULT_MAXIMUM_NUMBER_RELEASES;

            if ($message->getNumberOfReleases() < $maximumNumberOfReleases) {
                $releaseOptions = isset($this->queueSettings['releaseOptions']) ? $this->queueSettings['releaseOptions'] : [];
                $this->queue->release($message->getIdentifier(), $releaseOptions);
                $this->queue->reQueueMessage($message, $releaseOptions);
                $this->outputLine(
                    'Job execution for job (message: "%s", queue: "%s") failed (%d/%d trials) - RELEASE',
                    $message->getIdentifier(),
                    $this->queue->getName(),
                    $message->getNumberOfReleases() + 1,
                    $maximumNumberOfReleases + 1
                );

            } else {
                $this->queue->abort($message->getIdentifier());
                $this->outputLine(
                    'Job execution for job (message: "%s", queue: "%s") failed (%d/%d trials) - ABORTING',
                    $message->getIdentifier(),
                    $this->queue->getName(),
                    $message->getNumberOfReleases() + 1,
                    $maximumNumberOfReleases + 1
                );
            }
        }

        if ($messageCacheIdentifier !== null) {
            $this->messageCache->remove($messageCacheIdentifier);
        }
    }

    protected function outputLine(string $text, ...$arguments)
    {
        $this->output->outputLine($text, $arguments);
    }
}
