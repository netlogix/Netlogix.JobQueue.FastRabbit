<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit\Job;

use Flowpack\JobQueue\Common\Queue\QueueManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Booting\Scripts;
use Neos\Utility\ObjectAccess;
use function defined;
use function preg_replace;
use function rtrim;
use function sprintf;
use function strtolower;
use function trim;

class ConfigurationFactory
{
    /**
     * @var string
     * @Flow\InjectConfiguration(package="Netlogix.JobQueue.FastRabbit", path="supervisor.applicationName")
     */
    protected $applicationName;

    /**
     * @var QueueManager
     */
    protected $queueManager;

    /**
     * @var ConfigurationManager
     */
    private $configurationManager;

    public function injectQueueManager(QueueManager $queueManager): void
    {
        $this->queueManager = $queueManager;
    }

    public function injectConfigurationManager(ConfigurationManager $configurationManager): void
    {
        $this->configurationManager = $configurationManager;
    }

    /**
     * @param string $queueName
     * @return array<string, mixed>
     * @throws \Flowpack\JobQueue\Common\Exception
     * @throws \Neos\Flow\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \Neos\Flow\Exception
     */
    public function buildJobConfiguration(string $queueName): array
    {
        assert(defined('FLOW_PATH_FLOW'));
        assert(defined('FLOW_PATH_TEMPORARY_BASE'));

        $flowSettings = (array)$this->configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            'Neos.Flow'
        );

        $command = Scripts::buildPhpCommand(
            $flowSettings
        );
        $command .= sprintf(
            ' %s %s --queue=%s',
            escapeshellarg(\FLOW_PATH_FLOW . 'Scripts/flow.php'),
            escapeshellarg('flowpack.jobqueue.common:job:execute'),
            escapeshellarg($queueName)
        );

        $workerPool = (array)$this->configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            'Netlogix.JobQueue.FastRabbit.supervisor.workerPool'
        );

        $jobConfig = [
            'command' => $command,
            'queueSettings' => $this->queueManager->getQueueSettings($queueName),
            'cacheConfiguration' => $this->configurationManager->getConfiguration(
                ConfigurationManager::CONFIGURATION_TYPE_CACHES,
                'FlowPackJobQueueCommon_MessageCache'
            ),
            'queueName' => $queueName,
            'temporaryDirectoryBase' => \FLOW_PATH_TEMPORARY_BASE,
            'applicationIdentifier' => ObjectAccess::getPropertyPath($flowSettings, 'cache.applicationIdentifier'),
            'contextString' => ObjectAccess::getPropertyPath($flowSettings, 'core.context'),
            'workerPool' => $workerPool,
        ];

        return $jobConfig;
    }

    public function getJobName(string $queueName): string
    {
        return self::removeInvalidCharactersFromSupervisorIdentifier($this->applicationName . '-' . $queueName);
    }

    public function getJobConfigurationFilePath(string $queueName): string
    {
        return $this->getJobFilePath($queueName, 'json');
    }

    public function getJobSupervisorFilePath(string $queueName): string
    {
        return $this->getJobFilePath($queueName, 'conf');
    }

    public function getNumberOfProcesses(string $queueName): int
    {
        $queueSettings = $this->queueManager->getQueueSettings($queueName);
        return (int)(ObjectAccess::getPropertyPath($queueSettings, 'fastRabbit.numProcs') ?? 1);
    }

    protected function getJobFilePath(string $queueName, string $suffix): string
    {
        assert(defined('FLOW_PATH_CONFIGURATION'));
        $jobName = $this->getJobName($queueName);
        $pathPrefix = rtrim(FLOW_PATH_CONFIGURATION, '/') . '/Supervisor/';
        return sprintf($pathPrefix . 'program-%s.%s', $jobName, $suffix);
    }

    private static function removeInvalidCharactersFromSupervisorIdentifier(string $subject): string
    {
        /**
         * \p{xx}   A character with the xx unicode property.   https://php.net/manual/en/regexp.reference.escape.php
         * \pL      Every Letter                                https://php.net/manual/en/regexp.reference.unicode.php
         */
        $cleanupPattern = '%[^\\pL\\d]+%iUum';

        $subject = (string)preg_replace($cleanupPattern, '-', $subject);
        $subject = trim($subject, '-');
        return strtolower($subject);
    }
}
