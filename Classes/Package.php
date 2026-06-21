<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit;

use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Monitor\FileMonitor;
use Neos\Flow\ObjectManagement\CompileTimeObjectManager;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\ObjectManagement\Proxy\Compiler;
use Neos\Flow\Package\Package as BasePackage;
use Neos\Flow\SignalSlot\Dispatcher;
use Netlogix\JobQueue\FastRabbit\SingletonPreloading\AllSingletonsPreloader;

final class Package extends BasePackage
{
    public function boot(Bootstrap $bootstrap): void
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();
        assert($dispatcher instanceof Dispatcher);

        /**
         * @see Compiler::compiledClasses()
         * @see FileMonitor::emitFilesHaveChanged()
         * @see AllSingletonsPreloader::flush()
         */
        $dispatcher->connect(
            signalClassName: FileMonitor::class,
            signalName: 'filesHaveChanged',
            slotClassNameOrObject: fn () => static::flushSingletonsPreloaderCache($bootstrap->getObjectManager())
        );
    }

    private function flushSingletonsPreloaderCache(ObjectManagerInterface $objectManager): void
    {
        if ($objectManager instanceof CompileTimeObjectManager) {
            return;
        }
        $objectManager
            ->get(AllSingletonsPreloader::CACHE)
            ->flush();
    }
}
