<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit\Supervisor;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\Exception\SchemaValidationException;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
use Netlogix\JobQueue\FastRabbit\Job\ConfigurationFactory;
use Netlogix\JobQueue\FastRabbit\Queues\Locator;
use Netlogix\Supervisor\Model;
use Netlogix\Supervisor\Provider as ProviderInterface;
use Traversable;
use function array_keys;
use function array_map;
use function array_values;
use function assert;
use function file_put_contents;
use function in_array;
use function is_string;
use function iterator_to_array;
use function json_encode;
use function str_replace;

class Provider implements ProviderInterface
{
    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var string
     * @Flow\InjectConfiguration(package="Netlogix.JobQueue.FastRabbit", path="supervisor.applicationName")
     */
    protected $applicationName;

    /**
     * @var string
     * @Flow\InjectConfiguration(package="Netlogix.JobQueue.FastRabbit", path="supervisor.groupName")
     */
    protected $groupName;

    /**
     * @var array<string, string|int|bool>
     * @Flow\InjectConfiguration(package="Netlogix.JobQueue.FastRabbit", path="supervisor.program")
     */
    protected $programTemplate;

    /**
     * @var array<Model\Program>
     */
    protected $programs = [];

    public function injectObjectManager(ObjectManagerInterface $objectManager): void
    {
        $this->objectManager = $objectManager;
    }

    public function initializeObject(): void
    {
        $this->programs = iterator_to_array($this->getProgramsInternal(), false);
    }

    /**
     * @return array<Model\Program>
     */
    public function getPrograms(): array
    {
        return $this->programs;
    }

    /**
     * @return Traversable<Model\Program>
     * @throws SchemaValidationException
     */
    protected function getProgramsInternal(): Traversable
    {
        $queueNames = $this->collectQueueNames();
        foreach ($queueNames as $queueName) {

            $configFactory = new ConfigurationFactory();
            $jobConfig = $configFactory->buildJobConfiguration($queueName);
            $jobFilePath = $configFactory->getJobConfigurationFilePath($queueName);
            file_put_contents($jobFilePath, json_encode($jobConfig, \JSON_PRETTY_PRINT));

            $replacement = [
                '__APPLICATION_NAME__' => $this->applicationName,
                '__QUEUE_NAME__' => $queueName,
                '__CONFIG_FILE__' => $configFactory->getJobConfigurationFilePath($queueName),
                '__JOB_NAME__' => $configFactory->getJobName($queueName),
                '__NUMPROCS__' => $configFactory->getNumberOfProcesses($queueName),
            ];

            $settings = array_map(
                static function ($value) use ($replacement) {
                    if (is_string($value)) {
                        $value = str_replace(array_keys($replacement), array_values($replacement), $value);
                    }
                    return $value;
                },
                $this->programTemplate
            );

            $groupName = (string)str_replace(array_keys($replacement), array_values($replacement), $this->groupName);
            yield new Model\Program(
                $configFactory->getJobName($queueName),
                $groupName,
                (string)$settings['command'],
                $settings
            );
        }
    }

    /**
     * @Flow\CompileStatic
     * @param ObjectManagerInterface $objectManager
     * @return array<class-string<Locator>>
     */
    public static function collectLocatorNames(
        ObjectManagerInterface $objectManager
    ): array {
        $reflectionService = $objectManager->get(ReflectionService::class);
        assert($reflectionService instanceof ReflectionService);
        $locatorNames = $reflectionService->getAllImplementationClassNamesForInterface(Locator::class);
        return array_values($locatorNames);
    }

    /**
     * @return string[]
     * @throws SchemaValidationException
     */
    protected function collectQueueNames(): array
    {
        $locatorNames = self::collectLocatorNames($this->objectManager);
        $queueNames = [];
        foreach ($locatorNames as $locatorName) {
            $locator = $this->objectManager->get($locatorName);
            assert($locator instanceof Locator);
            foreach ($locator as $queueName) {
                if (in_array($queueName, $queueNames, true)) {
                    throw new SchemaValidationException(
                        sprintf('Duplicate supervisor config found for queue "%s".', $queueName),
                        1594829585
                    );
                }
                $queueNames[$queueName] = $queueName;
            }
        }
        return array_values($queueNames);
    }
}
