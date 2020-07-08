<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit\Command;

use Flowpack\JobQueue\Common\Queue\QueueManager;
use Neos\EventSourcing\EventListener\EventListenerInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Booting\Scripts;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
use Netlogix\JobQueue\FastRabbit\Job\ConfigurationFactory;

class SupervisorCommandController extends \Neos\Flow\Cli\CommandController
{
    /**
     * @var ObjectManagerInterface
     * @Flow\Inject
     */
    protected $objectManager;

    /**
     * @var QueueManager
     * @Flow\Inject
     */
    protected $queueManager;

    /**
     * @var ConfigurationManager
     * @Flow\Inject
     */
    protected $configurationManager;

    /**
     * @Flow\CompileStatic
     * @return array
     */
    public static function collectEventListenerClassNames(
        ObjectManagerInterface $objectManager
    ): array {
        $reflectionService = $objectManager->get(ReflectionService::class);
        return $reflectionService->getAllImplementationClassNamesForInterface(EventListenerInterface::class);
    }

    /**
     * @return void
     */
    public function createConfigCommand(): void
    {
        $pathPrefix = FLOW_PATH_DATA . '../Configuration/Supervisor/';
        foreach (glob($pathPrefix . 'program-*.conf') as $configFile) {
            unlink($configFile);
        }

        $factory = new ConfigurationFactory();

        $eventListenerClassNames = self::collectEventListenerClassNames($this->objectManager);
        foreach ($eventListenerClassNames as $eventListenerClassName) {
            $jobName = $factory->getJobNameForListenerClassName($eventListenerClassName);
            $jobConfiguration = $factory->buildJobConfigurationForListenerClassName($eventListenerClassName);
            $jobFilePath = sprintf($pathPrefix . 'program-%s.conf', $jobName);
            file_put_contents($jobFilePath, $jobConfiguration);
        }

        $groupConfiguration = $factory->buildGroupConfigurationForListenerClassnames(... $eventListenerClassNames);
        $groupFilePath = $pathPrefix . 'group.conf';
        file_put_contents($groupFilePath, $groupConfiguration);
    }

    public function forkWorkerCommand(string $queue): void
    {
        $command = Scripts::buildPhpCommand(
            $this->configurationManager->getConfiguration('Settings', 'Neos.Flow')
        );
        $command .= sprintf(
            ' %s %s --queue=%s',
            escapeshellarg(FLOW_PATH_FLOW . 'Scripts/flow.php'),
            escapeshellarg('flowpack.jobqueue.common:job:execute'),
            escapeshellarg($queue)
        );

        $jobConfig = [
            'command' => $command,
            'queueSettings' => $this->queueManager->getQueueSettings($queue),
            'cacheConfiguration' => $this->configurationManager->getConfiguration(
                'Caches',
                'FlowPackJobQueueCommon_MessageCache'
            ),
            'queueName' => $queue,
            'temporaryDirectoryBase' => FLOW_PATH_TEMPORARY_BASE,
            'applicationIdentifier' => $this->configurationManager->getConfiguration(
                ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
                'Neos.Flow.cache.applicationIdentifier'
            ),
            'contextString' => $this->configurationManager->getConfiguration('Settings', 'Neos.Flow.core.context'),
        ];

        $this->outputLine(\json_encode($jobConfig));
    }
}
