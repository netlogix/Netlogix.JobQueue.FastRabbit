<?php

namespace Netlogix\JobQueue\FastRabbit\PreventLoggingOfMissingInput;

use Flowpack\JobQueue\Common\Command\JobCommandController;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Cli\Exception\StopCommandException;
use Neos\Flow\Cli\Request;
use Neos\Flow\Mvc\Controller;
use Neos\Flow\Reflection\ClassReflection;
use Symfony\Component\Console\Exception\MissingInputException;

use function fgets;
use function rtrim;
use function stream_set_blocking;

/**
 * A preforked job worker is started as "flowpack.jobqueue.common:job:execute"
 * WITHOUT arguments and waits for the parent FastRabbit process to hand over the
 * queue name and the message cache identifier line by line via STDIN.
 *
 * If we let Flow fetch those missing required arguments through its interactive
 * prompt, Symfony Console reads STDIN byte by byte and calls
 * TerminalInputHelper::waitForInput() before every byte, which busy-polls STDIN
 * with a 100µs stream_select() loop. With ~150 idle workers that busy-poll
 * saturates the CPU.
 *
 * So we read the missing required arguments here with a plain BLOCKING fgets()
 * (a real blocking read sleeps at 0% CPU until the parent writes) and inject them
 * into the request. The original mapRequestArgumentsToControllerArguments() then
 * finds every argument present and never reaches the interactive prompt.
 *
 * When the parent process restarts (every 6 hours due to the loop's max wait
 * time) its end of the STDIN pipe closes, so fgets() returns false. That orphaned
 * worker is expected and stops cleanly via StopCommandException instead of
 * producing a tracked exception.
 */
#[Flow\Aspect]
#[Flow\Proxy(false)]
class JobCommandInitializationAspect
{
    #[Flow\Around('within(' . JobCommandController::class . ') && method(.*->mapRequestArgumentsToControllerArguments())')]
    public function readJobHandoffFromStdinBlocking(JoinPointInterface $joinPoint): void
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

        $request = $reflection
            ->getProperty('request')
            ->getValue($jobCommandController);
        assert($request instanceof Request);

        $arguments = $reflection
            ->getProperty('arguments')
            ->getValue($jobCommandController);
        assert($arguments instanceof Controller\Arguments);

        // Blockierend lesen statt Symfonys interaktivem Prompt (Busy-Poll).
        stream_set_blocking(\STDIN, true);
        foreach ($arguments as $argument) {
            assert($argument instanceof Controller\Argument);
            if (!$argument->isRequired() || $request->hasArgument($argument->getName())) {
                continue;
            }

            $line = fgets(\STDIN);
            if ($line === false) {
                // STDIN closed (orphaned worker after parent restart) -> stop cleanly.
                throw new StopCommandException();
            }

            $request->setArgument($argument->getName(), rtrim($line, "\r\n"));
        }

        try {
            // Findet jetzt alle Argumente im Request -> kein ask() -> kein Busy-Poll.
            $joinPoint->getAdviceChain()->proceed($joinPoint);
        } catch (MissingInputException $e) {
            throw new StopCommandException();
        }
    }
}
