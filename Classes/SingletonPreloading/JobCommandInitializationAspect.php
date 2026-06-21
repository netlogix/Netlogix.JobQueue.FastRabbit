<?php

namespace Netlogix\JobQueue\FastRabbit\SingletonPreloading;

use Flowpack\JobQueue\Common\Command\JobCommandController;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Cli\Request;
use Neos\Flow\Mvc\Controller;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ClassReflection;

#[Flow\Aspect]
class JobCommandInitializationAspect
{
    public function __construct(
        protected readonly ObjectManagerInterface $objectManager
    ) {
    }

    #[Flow\AfterReturning('within(' . JobCommandController::class . ') && method(.*->initializeCommandMethodArguments())')]
    public function preloadSingletonsWhenJobCommandControllerGetsInitialized(JoinPointInterface $joinPoint): void
    {
        $jobCommandController = $joinPoint->getProxy();
        assert($jobCommandController instanceof JobCommandController);

        $reflection = new ClassReflection($jobCommandController);

        $commandMethodName = $reflection
            ->getProperty('commandMethodName')
            ->getValue($jobCommandController);

        if ($commandMethodName !== 'executeCommand') {
            return;
        }

        $request = $reflection
            ->getProperty('request')
            ->getValue($jobCommandController);
        assert($request instanceof Request);

        $arguments = $reflection
            ->getProperty('arguments')
            ->getValue($jobCommandController);
        assert($arguments instanceof Controller\Arguments);

        foreach ($arguments as $argument) {
            assert($argument instanceof Controller\Argument);
            if ($argument->isRequired() && !$request->hasArgument($argument->getName())) {
                // only preload if the request is blocked by fetching an argument via stdin
                $this->objectManager
                    ->get(SingletonsPreloader::class)
                    ->collect();
                return;
            }
        }
    }
}
