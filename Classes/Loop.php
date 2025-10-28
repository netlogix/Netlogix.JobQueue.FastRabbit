<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit;

use Neos\Flow\Annotations as Flow;
use Netlogix\JobQueue\Pool\Pool;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use t3n\JobQueue\RabbitMQ\Queue\RabbitQueue;

use function count;
use function max;

#[Flow\Proxy(false)]
final class Loop
{
    public const int SIX_HOURS_IN_SECONDS = 21600;

    public function __construct(
        /**
         * The Queue to watch
         */
        protected RabbitQueue $queue,

        protected readonly Pool $poolObject,

        /**
         * Time in seconds after which the loop should exit
         */
        protected readonly ?int $exitAfter
    ) {
    }

    public function runMessagesOnWorker(Worker $worker)
    {
        $this
            ->poolObject
            ->runLoop(function (Pool $pool) use ($worker) {
                $worker->prepare();

                $runDueJobs = $pool->eventLoop->addPeriodicTimer(
                    interval: 0.01,
                    callback: fn () => $this->runDueJob($pool, $worker)
                );

                if ($this->exitAfter) {
                    $pool->eventLoop->addTimer(
                        interval: max($this->exitAfter, 1),
                        callback: function () use ($pool, $runDueJobs) {
                            $pool->eventLoop->cancelTimer($runDueJobs);
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
