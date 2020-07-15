<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit\Queues;

use Flowpack\JobQueue\Common\Queue\QueueManager;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use t3n\JobQueue\RabbitMQ\Queue\RabbitQueue;

class ConfigurationBasedQueues implements Locator
{
    /**
     * @var \ArrayIterator
     */
    protected $queues;

    public function injectObjectManager(ObjectManagerInterface $objectManager)
    {
        $configurationManager = $objectManager->get(ConfigurationManager::class);
        $queues = array_keys(
            $configurationManager->getConfiguration(
                ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
                'Flowpack.JobQueue.Common.queues'
            )
        );

        $queueManager = $objectManager->get(QueueManager::class);
        $queues = array_filter($queues, static function (string $queueName) use ($queueManager) {
            $queueConfig = $queueManager->getQueueSettings($queueName);
            if (!is_a($queueConfig['className'], RabbitQueue::class, true)) {
                return false;
            }
            if (!($queueConfig['generateSupervisorConfigForFastRabbit'] ?? false)) {
                return false;
            }
            return true;
        });

        $this->queues = new \ArrayIterator($queues);
    }

    public function next()
    {
        $this->queues->next();
    }

    public function key()
    {
        return $this->queues->key();
    }

    public function valid()
    {
        return $this->queues->valid();
    }

    public function rewind()
    {
        $this->queues->rewind();
    }

    public function current(): string
    {
        return $this->queues->current();
    }
}
