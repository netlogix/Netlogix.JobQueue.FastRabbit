<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit;

use Neos\Flow\Annotations as Flow;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use t3n\JobQueue\RabbitMQ\Queue\RabbitQueue;

/**
 * @Flow\Proxy(false)
 */
final class Loop
{
    protected $queue;

    /**
     * Unix timestamp after which the Loop should exit
     *
     * @var int|null
     */
    protected $exitAfterTimestamp;

    /**
     * Timeout in seconds when waiting for new messages
     *
     * @var int|null
     */
    protected $timeout;

    /**
     * @param RabbitQueue $queue The Queue to watch
     * @param int $exitAfter Time in seconds after which the loop should exit
     */
    public function __construct(RabbitQueue $queue, int $exitAfter = 0)
    {
        $this->queue = $queue;
        $this->exitAfterTimestamp = $exitAfter > 0 ? time() + $exitAfter : null;
        $this->timeout = $exitAfter > 0 ? $exitAfter : null;
    }

    public function runMessagesOnWorker(Worker $worker)
    {
        $worker->prepare();
        do {
            try {
                $message = $this->queue->waitAndReserve($this->timeout);
                $worker->executeMessage($message);
            } catch (AMQPTimeoutException $e) {
            }

            if ($this->exitAfterTimestamp !== null && time() >= $this->exitAfterTimestamp) {
                break;
            }
        } while (true);
    }
}
