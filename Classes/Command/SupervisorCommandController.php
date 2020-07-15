<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit\Command;

use Flowpack\JobQueue\Common\Queue\QueueManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Configuration\Exception\InvalidConfigurationTypeException;
use Neos\Flow\Core\Booting\Scripts;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
use Netlogix\JobQueue\FastRabbit\Job\ConfigurationFactory;
use Netlogix\JobQueue\FastRabbit\Queues\Locator;

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
    public static function collectQueueNames(
        ObjectManagerInterface $objectManager
    ): array {
        $reflectionService = $objectManager->get(ReflectionService::class);
        $locatorNames = $reflectionService->getAllImplementationClassNamesForInterface(Locator::class);
        $queues = [];
        foreach ($locatorNames as $locatorName) {
            $locator = $objectManager->get($locatorName);
            assert($locator instanceof Locator);
            $queues = array_merge($queues, iterator_to_array($locator, true));
        }
        return $queues;
    }

    public function createCommand(): void
    {
        $this->createSupervisorGroupConfigCommand();
        $queueNames = self::collectQueueNames($this->objectManager);
        foreach ($queueNames as $queueName) {
            $this->createSupervisorProcessConfigCommand($queueName);
            $this->createWorkerConfigCommand($queueName);
        }
    }

    /**
     * @return void
     */
    public function createSupervisorGroupConfigCommand(): void
    {
        $pathPrefix = FLOW_PATH_CONFIGURATION . 'Supervisor/';
        foreach (glob($pathPrefix . 'program-*.conf') as $configFile) {
            unlink($configFile);
        }

        $factory = new ConfigurationFactory();

        $queueNames = self::collectQueueNames($this->objectManager);

        $groupConfiguration = $factory->buildGroupConfigurationForQueues(... $queueNames);
        $groupFilePath = $pathPrefix . 'group.conf';
        file_put_contents($groupFilePath, $groupConfiguration);
    }

    /**
     * @return void
     */
    public function createSupervisorProcessConfigCommand(string $queueName): void
    {
        $factory = new ConfigurationFactory();
        $jobConfiguration = $factory->buildJobConfigurationForQueue($queueName);

        $jobFilePath = $factory->getJobSupervisorFile($queueName);
        file_put_contents($jobFilePath, $jobConfiguration);
    }

    /**
     * @param string $queueName
     * @throws \Flowpack\JobQueue\Common\Exception
     * @throws InvalidConfigurationTypeException
     * @throws \Neos\Flow\Exception
     */
    public function createWorkerConfigCommand(string $queueName): void
    {
        $command = Scripts::buildPhpCommand(
            $this->configurationManager->getConfiguration('Settings', 'Neos.Flow')
        );
        $command .= sprintf(
            ' %s %s --queue=%s',
            escapeshellarg(FLOW_PATH_FLOW . 'Scripts/flow.php'),
            escapeshellarg('flowpack.jobqueue.common:job:execute'),
            escapeshellarg($queueName)
        );

        $jobConfig = [
            'command' => $command,
            'queueSettings' => $this->queueManager->getQueueSettings($queueName),
            'cacheConfiguration' => $this->configurationManager->getConfiguration(
                ConfigurationManager::CONFIGURATION_TYPE_CACHES,
                'FlowPackJobQueueCommon_MessageCache'
            ),
            'queueName' => $queueName,
            'temporaryDirectoryBase' => FLOW_PATH_TEMPORARY_BASE,
            'applicationIdentifier' => $this->configurationManager->getConfiguration(
                ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
                'Neos.Flow.cache.applicationIdentifier'
            ),
            'contextString' => $this->configurationManager->getConfiguration(
                ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
                'Neos.Flow.core.context'
            ),
            'workerPool' => $this->configurationManager->getConfiguration(
                ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
                'Netlogix.JobQueue.FastRabbit.supervisor.workerPool'
            ),
        ];

        $factory = new ConfigurationFactory();
        $jobFilePath = $factory->getJobConfigurationFile($queueName);
        file_put_contents($jobFilePath, json_encode($jobConfig, JSON_PRETTY_PRINT));
    }
}
