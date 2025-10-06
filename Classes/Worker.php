<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit;

use Flowpack\JobQueue\Common\Job\JobManager;
use Flowpack\JobQueue\Common\Queue\Message;
use Neos\Cache\Frontend\FrontendInterface;
use Neos\Flow\Cli\ConsoleOutput;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use t3n\JobQueue\RabbitMQ\Queue\RabbitQueue;

use function array_shift;

final class Worker
{
    protected readonly ConsoleOutput $output;

    /**
     * @var array{input: InputStream, process: Process}[]
     */
    private array $pool = [];

    /**
     * A pool size of 1 means one standby while 1 is working.
     */
    protected int $poolSize = 1;

    public function __construct(
        protected readonly string $command,
        protected readonly RabbitQueue $queue,
        protected readonly array $queueSettings,
        protected readonly FrontendInterface $messageCache,
        protected readonly Lock $lock
    ) {
    }

    public function prepare(): void
    {
        $this->cleanPool();

        $this->output = new ConsoleOutput();
        $this->output->outputLine('Watching queue <b>"%s"</b>', [$this->queue->getName()]);
    }

    public function executeMessage(Message $message): void
    {
        $messageCacheIdentifier = sha1(serialize($message));
        $this->messageCache->set($messageCacheIdentifier, $message);

        $process = $this->lock->run(
            fn () => $this->runFromPool($messageCacheIdentifier)
        );

        if ($process->getExitCode() === 0) {
            $this->queue->finish($message->getIdentifier());
            $this->output->outputLine(
                '<success>Successfully executed job "%s"</success>',
                [$message->getIdentifier()]
            );
            $this->output->outputLine('Output: %s', [$process->getOutput()]);
        } else {
            $maximumNumberOfReleases = isset($this->queueSettings['maximumNumberOfReleases'])
                ? (int) $this->queueSettings['maximumNumberOfReleases']
                : JobManager::DEFAULT_MAXIMUM_NUMBER_RELEASES;

            if ($message->getNumberOfReleases() < $maximumNumberOfReleases) {
                $releaseOptions = isset($this->queueSettings['releaseOptions']) ? $this->queueSettings['releaseOptions'] : [];
                $this->queue->release($message->getIdentifier(), $releaseOptions);
                $this->queue->reQueueMessage($message, $releaseOptions);
                $this->output->outputLine('Output: %s', [$process->getOutput()]);
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
                $this->output->outputLine('Output: %s', [$process->getOutput()]);
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
        }

        if ($messageCacheIdentifier !== null) {
            $this->messageCache->remove($messageCacheIdentifier);
        }
    }

    /**
     * @return array{input: InputStream, process: Process}
     */
    private function createProcess(): array
    {
        $input = new InputStream();
        $process = Process::fromShellCommandline(
            command: $this->command,
            input: $input,
            timeout: 0
        );
        $process->start();
        return ['input' => $input, 'process' => $process];
    }

    private function runFromPool(string $messageCacheIdentifier): Process
    {
        $this->cleanPool();
        ['input' => $input, 'process' => $process] = array_shift($this->pool);
        $this->pool[] = $this->createProcess();

        assert($input instanceof InputStream);
        assert($process instanceof Process);

        $input->write($messageCacheIdentifier . PHP_EOL);

        $process->wait();
        return $process;
    }

    private function cleanPool(): void
    {
        $this->pool = array_filter(
            $this->pool,
            fn (array $item) => $item['process']->isRunning()
        );
        while (count($this->pool) < $this->poolSize) {
            $this->pool[] = $this->createProcess();
        }
    }
}
