<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit\Job;

use Neos\Flow\Annotations as Flow;

class ConfigurationFactory
{
    const __CONFIG_FILE__ = '__CONFIG_FILE__';
    const __QUEUE_NAME__ = '__QUEUE_NAME__';
    const __JOB_NAME__ = '__JOB_NAME__';
    const __CONTEXT__ = '__CONTEXT__';

    /**
     * @var string
     * @Flow\InjectConfiguration(package="Netlogix.JobQueue.FastRabbit", path="supervisor.contextName")
     */
    protected $contextName;

    /**
     * @var string
     * @Flow\InjectConfiguration(package="Netlogix.JobQueue.FastRabbit", path="supervisor.programTemplate")
     */
    protected $programTemplate;

    /**
     * @var string
     * @Flow\InjectConfiguration(package="Netlogix.JobQueue.FastRabbit", path="supervisor.groupTemplate")
     */
    protected $groupTemplate;

    public function getShortNameForQueueName(string $queueName): string
    {
        return preg_replace('%[^a-z0-9]%iUm', '-', strtolower($queueName));
    }

    public function getJobNameForQueueName(string $queueName): string
    {
        return $this->getContextName() . '-' . $this->getShortNameForQueueName($queueName);
    }

    public function buildJobConfigurationForQueue(string $queueName): string
    {
        $jobName = $this->getJobNameForQueueName($queueName);

        $config = $this->programTemplate;
        $config = str_replace(self::__CONFIG_FILE__, $this->getJobConfigurationFile($queueName), $config);
        $config = str_replace(self::__QUEUE_NAME__, $queueName, $config);
        $config = str_replace(self::__JOB_NAME__, $jobName, $config);
        $config = str_replace(self::__CONTEXT__, $this->contextName, $config);

        return $config;
    }

    public function buildGroupConfigurationForQueues(string ...$queueNames): string
    {
        $jobNames = array_map([$this, 'getJobNameForQueueName'], $queueNames);

        $programs = $this->groupTemplate;
        $programs = str_replace('__PROGRAMS__', join(',', $jobNames), $programs);
        $programs = str_replace('__CONTEXT__', $this->contextName, $programs);

        return $programs;
    }

    public function getJobConfigurationFile(string $queueName): string
    {
        return $this->getJobFilePath($queueName, 'json');
    }

    public function getJobSupervisorFile(string $queueName): string
    {
        return $this->getJobFilePath($queueName, 'conf');
    }

    protected function getJobFilePath(string $queueName, string $suffix): string
    {
        $jobName = $this->getJobNameForQueueName($queueName);
        $pathPrefix = rtrim(FLOW_PATH_CONFIGURATION, '/') . '/Supervisor/';
        return sprintf($pathPrefix . 'program-%s.%s', $jobName, $suffix);
    }

    protected function getContextName(): string
    {
        return trim(preg_replace('%[^\\pL]+%iUum', '-', $this->contextName), '-');
    }
}
