<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit\Cache;

use Neos\Cache\Frontend\FrontendInterface;
use Neos\Flow\Core\ApplicationContext;
use Neos\Flow\Utility\Environment;

class CacheFactory
{
    public static function get(array $config): FrontendInterface
    {
        $temporaryDirectoryBase = $config['temporaryDirectoryBase'];
        $applicationIdentifier = $config['applicationIdentifier'];
        $contextString = $config['contextString'];

        $frontendClassName = $config['cacheConfiguration']['frontend'];
        $backendClassName = $config['cacheConfiguration']['backend'];
        $backendOptions = $config['cacheConfiguration']['backendOptions'];

        $applicationContext = new ApplicationContext($contextString);
        $environment = new Environment($applicationContext);
        $environment->setTemporaryDirectoryBase($temporaryDirectoryBase);

        $factory = new \Neos\Flow\Cache\CacheFactory($applicationContext, $environment, $applicationIdentifier);

        return $factory->create(
            'FlowPackJobQueueCommon_MessageCache',
            $frontendClassName,
            $backendClassName,
            $backendOptions
        );
    }
}
