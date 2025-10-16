<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit;

use Flowpack\JobQueue\Common\Job\JobManager;
use Flowpack\JobQueue\Common\Queue\Message;
use Neos\Cache\Frontend\FrontendInterface;
use Neos\Flow\Cli\ConsoleOutput;
use React\ChildProcess\Process;
use React\EventLoop;
use t3n\JobQueue\RabbitMQ\Queue\RabbitQueue;

use function array_shift;
use function fputs;

use function max;

use const STDERR;
use const STDOUT;

final class Worker
{
    protected readonly ConsoleOutput $output;

    private readonly EventLoop\LoopInterface $loop;

    /**
     * @var Process[]
     */
    private array $pool = [];

    /**
     * When a child process is assigned a task, the pool is restocked to this
     * amount. So this is the number of idle processes at any time. The total
     * number of processes can be higher if there are busy ones.
     */
    private readonly int $poolSize;

    public function __construct(
        protected readonly string $command,
        protected readonly RabbitQueue $queue,
        protected readonly array $queueSettings,
        protected readonly FrontendInterface $messageCache,
        protected readonly Lock $lock
    ) {
        $this->loop = EventLoop\Loop::get();
        $this->poolSize = max(0, (int) ($queueSettings['poolSize'] ?? 1));
    }

    public function shutdownObject()
    {
        foreach ($this->pool as $process) {
            $process->terminate();
            $process->stdin->close();
        }
        $this->pool = [];
    }

    public function prepare(): void
    {
        $this->fillPool($this->poolSize);

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
        } else {
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
        }

        if ($messageCacheIdentifier !== null) {
            $this->messageCache->remove($messageCacheIdentifier);
        }
    }

    private function createProcess(): Process
    {
        $process = new Process($this->command);
        $timer = $this->loop->addPeriodicTimer(0.01, function () {
            // TODO: Add keepalive for database if necessary
        });
        $process->on('exit', function () use ($timer) {
            $this->loop->cancelTimer($timer);
            $this->loop->stop();
        });
        $process->start(loop: $this->loop, interval: 0.01);
        return $process;
    }

    private function runFromPool(string $messageCacheIdentifier): Process
    {
        $this->fillPool($this->poolSize + 1); // Overfill
        $process = array_shift($this->pool);
        assert($process instanceof Process);

        $process->stdout->on('data', fn ($chunk) => fputs(STDOUT, $chunk));
        $process->stderr->on('data', fn ($chunk) => fputs(STDERR, $chunk));

        $process->stdin->write($messageCacheIdentifier . PHP_EOL);

        $this->loop->run();

        return $process;
    }

    private function fillPool(int $poolSize): void
    {
        $poolSize = max($poolSize, 0);
        $this->pool = array_filter(
            $this->pool,
            fn (Process $process) => $process->isRunning()
        );
        while (count($this->pool) < $poolSize) {
            $this->pool[] = $this->createProcess();
        }
    }
}
