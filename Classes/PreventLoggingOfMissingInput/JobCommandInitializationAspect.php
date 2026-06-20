<?php

namespace Netlogix\JobQueue\FastRabbit\PreventLoggingOfMissingInput;

use Flowpack\JobQueue\Common\Command\JobCommandController;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Cli\Exception\StopCommandException;
use Neos\Flow\Reflection\ClassReflection;
use Symfony\Component\Console\Exception\MissingInputException;

/**
 * There might be orphaned worker processes when the parent process
 * of FastRabbit loop restarts due to its max wait time.
 *
 * Those are still waiting for message identifiers, which can never
 * be fulfilled once their STDIN closes, so a MissingInputException
 * gets thrown.
 *
 * This is expected and will happen every 6 hours due to the current
 * configuration. We don't need those exceptions tracked. So redirect
 * to StopCommandException instead.
 */
#[Flow\Aspect]
#[Flow\Proxy(false)]
class JobCommandInitializationAspect
{
    #[Flow\Around('within(' . JobCommandController::class . ') && method(.*->mapRequestArgumentsToControllerArguments())')]
    public function preventLoggingOfMissingInputExceptions(JoinPointInterface $joinPoint): void
    {
        $jobCommandController = $joinPoint->getProxy();
        assert($jobCommandController instanceof JobCommandController);

        $reflection = new ClassReflection($jobCommandController);

        $commandMethodName = $reflection
            ->getProperty('commandMethodName')
            ->getValue($jobCommandController);

        if ($commandMethodName !== 'executeCommand') {
            $joinPoint->getAdviceChain()->proceed($joinPoint);
            return;
        }

        try {
            $joinPoint->getAdviceChain()->proceed($joinPoint);
        } catch (MissingInputException $e) {
            throw new StopCommandException();
        }
    }
}
