<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit;

use Closure;
use Flowpack\JobQueue\Common\Job\JobManager;
use Flowpack\JobQueue\Common\Queue\Message;
use Neos\Cache\Frontend\FrontendInterface;
use Neos\Flow\Cli\ConsoleOutput;
use Netlogix\JobQueue\Pool\Pool;
use React\ChildProcess\Process;
use t3n\JobQueue\RabbitMQ\Queue\RabbitQueue;

use function sha1;

final class Worker
{
    protected readonly ConsoleOutput $output;

    /**
     * Invoked after a job has finished (successfully or not) so the loop
     * can pick up the next message without waiting for the next periodic poll.
     */
    private ?Closure $onJobFinished = null;

    public function __construct(
        protected readonly string $command,
        protected readonly Pool $poolObject,
        protected readonly RabbitQueue $queue,
        protected readonly array $queueSettings,
        protected readonly FrontendInterface $messageCache,
        protected readonly Lock $lock
    ) {
    }

    public function onJobFinished(Closure $callback): void
    {
        $this->onJobFinished = $callback;
    }

    public function prepare(): void
    {
        $this->output = new ConsoleOutput();
        $this->output->outputLine('Watching queue <b>"%s"</b>', [$this->queue->getName()]);
    }

    public function executeMessage(Message $message): void
    {
        $messageCacheIdentifier = sha1(serialize($message));
        $this->messageCache->set($messageCacheIdentifier, $message);

        $process = $this->lock->run(
            fn () => $this->poolObject->runPayload(payload: $message->getPayload(), queueName: $this->queue->getName()),
        );
        assert($process instanceof Process);

        $process->on(Pool::EVENT_SUCCESS, function () use ($message) {
            $this->queue->finish($message->getIdentifier());
            $this->notifyJobFinished();
            $this->output->outputLine(
                '<success>Successfully executed job "%s"</success>',
                [$message->getIdentifier()]
            );
        });

        $process->on(Pool::EVENT_ERROR, function () use ($message) {
            $this->notifyJobFinished();
            $maximumNumberOfReleases = isset($this->queueSettings['maximumNumberOfReleases'])
                ? (int) $this->queueSettings['maximumNumberOfReleases']
                : JobManager::DEFAULT_MAXIMUM_NUMBER_RELEASES;

            if ($message->getNumberOfReleases() < $maximumNumberOfReleases) {
                $releaseOptions = isset($this->queueSettings['releaseOptions']) ? $this->queueSettings['releaseOptions'] : [];
                $this->queue->release($message->getIdentifier(), $releaseOptions);
                $this->queue->reQueueMessage($message, $releaseOptions);
                $this->output->outputLine(
                    '<error>Job execution for job (message: "%s", queue: "%s") failed (%d/%d trials) - RELEASE</error>',
                    [
                        $message->getIdentifier(),
                        $this->queue->getName(),
                        $message->getNumberOfReleases() + 1,
                        $maximumNumberOfReleases + 1,
                    ]
                );
            } else {
                $this->queue->abort($message->getIdentifier());
                $this->output->outputLine(
                    '<error>Job execution for job (message: "%s", queue: "%s") failed (%d/%d trials) - ABORTING</error>',
                    [
                        $message->getIdentifier(),
                        $this->queue->getName(),
                        $message->getNumberOfReleases() + 1,
                        $maximumNumberOfReleases + 1,
                    ]
                );
            }
        });
    }

    private function notifyJobFinished(): void
    {
        if ($this->onJobFinished !== null) {
            ($this->onJobFinished)();
        }
    }
}
