<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit;

use Neos\Flow\Annotations as Flow;
use t3n\JobQueue\RabbitMQ\Queue\RabbitQueue;

/**
 * @Flow\Proxy(false)
 */
final class Loop
{
    protected $queue;

    public function __construct(RabbitQueue $queue)
    {
        $this->queue = $queue;
    }

    public function runMessagesOnWorker(Worker $worker)
    {
        $worker->prepare();
        do {
            $message = $this->queue->waitAndReserve();
            $worker->executeMessage($message);
        } while (true);
    }
}
