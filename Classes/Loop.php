<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit;

use Neos\Flow\Annotations as Flow;
use Netlogix\JobQueue\Polling\PollScheduler;
use Netlogix\JobQueue\Pool\Pool;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use t3n\JobQueue\RabbitMQ\Queue\RabbitQueue;

use function count;
use function max;

#[Flow\Proxy(false)]
final class Loop
{
    public const int SIX_HOURS_IN_SECONDS = 21600;

    /**
     * Baseline poll interval in seconds. An idle queue blocks in the AMQP wait()
     * anyway, so this only governs how often a worker re-checks while a job runs;
     * the immediate poll on job completion keeps throughput up.
     */
    public const float DEFAULT_POLL_INTERVAL = 0.1;

    public function __construct(
        /**
         * The Queue to watch
         */
        protected RabbitQueue $queue,

        protected readonly Pool $poolObject,

        /**
         * Time in seconds after which the loop should exit
         */
        protected readonly ?int $exitAfter,

        /**
         * Baseline poll interval in seconds
         */
        protected readonly float $pollingInterval = self::DEFAULT_POLL_INTERVAL
    ) {
    }

    public function runMessagesOnWorker(Worker $worker)
    {
        $this
            ->poolObject
            ->runLoop(function (Pool $pool) use ($worker) {
                $worker->prepare();

                $scheduler = PollScheduler::create(
                    loop: $pool->eventLoop,
                    tryToPickUpWork: fn () => $this->runDueJob($pool, $worker),
                    hasCapacity: fn () => count($pool) === 0,
                    interval: $this->pollingInterval
                );

                // Pick up the next message immediately once a job finished instead
                // of waiting for the next periodic tick.
                $worker->onJobFinished(fn () => $scheduler->requestImmediatePoll());

                $scheduler->start();

                if ($this->exitAfter) {
                    $pool->eventLoop->addTimer(
                        interval: max($this->exitAfter, 1),
                        callback: function () use ($pool, $scheduler) {
                            $scheduler->stop();
                            $checkForPoolToClear = $pool->eventLoop->addPeriodicTimer(
                                interval: 1,
                                callback: function () use ($pool, &$checkForPoolToClear) {
                                    if (count($pool) === 0) {
                                        $pool->eventLoop->cancelTimer($checkForPoolToClear);
                                        $pool->eventLoop->stop();
                                    }
                                }
                            );
                        }
                    );
                }
            });
    }

    private function runDueJob(Pool $pool, Worker $worker): void
    {
        /**
         * No parallel execution of multiple messages here, create multiple
         * fast rabbit instances connected instead.
         * Counting the running instances in the pool only prevents the
         * pool from spawning too many workers.
         */
        if (count($pool)) {
            return;
        }
        try {
            $message = $this->queue->waitAndReserve(10);
            if ($message) {
                $pool->eventLoop->futureTick(fn () => $worker->executeMessage($message));
            }
        } catch (AMQPTimeoutException $e) {
        }
    }
}
