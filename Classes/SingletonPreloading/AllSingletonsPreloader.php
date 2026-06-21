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
class AllSingletonsPreloader implements SingletonsPreloader
{
    public const string CACHE = 'Netlogix.JobQueue.FakeQueue:SingletonPreloaderCache';

    #[Flow\InjectConfiguration(path: 'AllSingletonsPreloader.ignoreClassNames', package: 'Netlogix.JobQueue.FastRabbit')]
    protected array $ignoreClassNames = [];

    #[Flow\Inject(name: AllSingletonsPreloader::CACHE, lazy: false)]
    protected VariableFrontend $cache;

    #[Flow\Inject(lazy: false)]
    protected ObjectManager $objectManager;

    #[Flow\Inject(lazy: false)]
    protected ReflectionService $reflectionService;

    public function collect(): void
    {
        foreach ($this->getClassList() as $className => $buildInstance) {
            $this->preload(className: $className, buildInstance: $buildInstance);
        }
        $this->pauseExpiringObjects();
    }

    /**
     * @return array<string, bool>
     */
    protected function getClassList(): array
    {
        if ($this->cache->has('classList')) {
            return $this->cache->get('classList');
        } else {
            $list = [... $this->buildClassList()];
            $this->cache->set('classList', $list);
            return $list;
        }
    }

    protected function preload(string $className, bool $buildInstance): void
    {
        try {
            $buildInstance
                ? $this->objectManager->get($className)
                : class_exists(class: $className, autoload: true);
        } catch (Throwable) {
            // ignore
        }
    }

    protected function pauseExpiringObjects()
    {
        if ($this->objectManager->has(EntityManagerInterface::class)) {
            $this->objectManager
                ->get(EntityManagerInterface::class)
                ->getConnection()
                ->close();
        }
        if ($this->objectManager->has(Connection::class)) {
            $this->objectManager
                ->get(Connection::class)
                ->close();
        }
        // TODO: There are other objects that might expire, for example ´
    }

    /**
     * @return Traversable<string, bool>
     */
    protected function buildClassList(): Traversable
    {
        foreach (self::getSingletonClassNamesFromReflection($this->objectManager) as $className => $buildInstance) {
            yield $className => $buildInstance && !$this->ignoreClassName($className);
        }
    }

    protected function ignoreClassName(string $className): bool
    {
        foreach ($this->ignoreClassNames as $ignoredClassName) {
            if (is_a($className, $ignoredClassName, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, bool>
     */
    #[Flow\CompileStatic]
    public static function getSingletonClassNamesFromReflection(ObjectManagerInterface $objectManager): array
    {
        $reflection = $objectManager->get(ReflectionService::class);
        assert($reflection instanceof ReflectionService);
        $classNames = [];
        foreach ($reflection->getAllClassNames() as $className) {
            try {
                if ($objectManager->getScope($className) !== Configuration::SCOPE_SINGLETON) {
                    /**
                     * Only preload singletons
                     */
                    $classNames[$className] = false;
                    continue;
                }
            } catch (\Exception $e) {
                $classNames[$className] = false;
                continue;
            }

            $constructParameters = $reflection->getMethodParameters($className, '__construct');
            if (count($constructParameters)) {
                /**
                 * Skip preloading for classes with constructor arguments because they are
                 * likely to depend on stateful objects that, in one way or other, expire,
                 * like database connections.
                 */
                $classNames[$className] = false;
            } else {
                $classNames[$className] = true;
            }
        }

        return $classNames;
    }
}
