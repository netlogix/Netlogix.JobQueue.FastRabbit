<?php

namespace Netlogix\JobQueue\FastRabbit\SingletonPreloading;

use Neos\Flow\Core\Bootstrap;
use Neos\Flow\ObjectManagement\Configuration\Configuration;
use Neos\Flow\ObjectManagement\ObjectManager;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
use Neos\Flow\Annotations as Flow;
use Throwable;

use Traversable;

use function array_filter;
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
    #[Flow\InjectConfiguration(path: 'AllSingletonsPreloader.ignoreClassNames', package: 'Netlogix.JobQueue.FastRabbit')]
    protected array $ignoreClassNames = [];

    public function __construct(
        protected readonly ObjectManager $objectManager,
        protected readonly ReflectionService $reflectionService
    ) {
    }

    public function collect(): void
    {
        $objectManager = Bootstrap::$staticObjectManager;
        foreach ($this->getSingletonClassNames($objectManager) as $className) {
            try {
                $objectManager->get($className);
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * @return Traversable<string>
     */
    public function getSingletonClassNames(ObjectManagerInterface $objectManager): Traversable
    {
        foreach (self::getSingletonClassNamesFromReflection($objectManager) as $className) {
            foreach ($this->ignoreClassNames as $ignoredClassName) {
                if (is_a($className, $ignoredClassName, true)) {
                    continue;
                }
            }
            yield $className;
        }
    }

    #[Flow\CompileStatic]
    public static function getSingletonClassNamesFromReflection(ObjectManagerInterface $objectManager): array
    {
        return array_filter(
            array: $objectManager->get(ReflectionService::class)->getAllClassNames(),
            callback: static function ($className) use ($objectManager): bool {
                try {
                    return $objectManager->getScope($className) === Configuration::SCOPE_SINGLETON;
                } catch (\Exception $e) {
                    return false;
                }
            }
        );
    }
}
