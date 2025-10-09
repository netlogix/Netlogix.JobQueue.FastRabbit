<?php

namespace Netlogix\JobQueue\FastRabbit\SingletonPreloading;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\ObjectManagement\Configuration\Configuration;
use Neos\Flow\ObjectManagement\ObjectManager;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
use Neos\Flow\Annotations as Flow;
use Throwable;
use Traversable;

use function class_exists;
use function is_a;

/**
 * Fetch all known singleton classes from the ObjectManager to have
 * all of them in the memory and ready to use.
 *
 * This should not be used "as is" because there's a high risk of
 * creating singletons with expiring connections, like, for example,
 * database connections.
 *
 * It's not enough to just exclude those database connections here
 * because there might be other singletons depending on those connections
 * through constructor injection, which triggers loading them anyway.
 */
final class NoneSingletonsPreloader implements SingletonsPreloader
{
    public function collect(): void
    {
    }

}
