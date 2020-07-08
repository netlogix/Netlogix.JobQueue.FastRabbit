<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit\Job;

use Neos\Flow\Annotations as Flow;

class ConfigurationFactory
{
    const __CLASS__ = '__CLASS__';
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

    public function getShortNameForListenerClassName(string $eventListenerClassName): string
    {
        return preg_replace('%[^a-z0-9]%iUm', '-', strtolower($eventListenerClassName));
    }

    public function getJobNameForListenerClassName(string $eventListenerClassName): string
    {
        return $this->getContextName() . '-' . $this->getShortNameForListenerClassName($eventListenerClassName);
    }

    public function buildJobConfigurationForListenerClassName(string $eventListenerClassName): string
    {
        $jobName = $this->getJobNameForListenerClassName($eventListenerClassName);

        $config = $this->programTemplate;
        $config = str_replace(self::__CLASS__, $eventListenerClassName, $config);
        $config = str_replace(self::__JOB_NAME__, $jobName, $config);
        $config = str_replace(self::__CONTEXT__, $this->contextName, $config);

        return $config;
    }

    public function buildGroupConfigurationForListenerClassnames(string ...$listenerClassNames): string
    {
        $jobNames = array_map([$this, 'getJobNameForListenerClassName'], $listenerClassNames);

        $programs = $this->groupTemplate;
        $programs = str_replace('__PROGRAMS__', join(',', $jobNames), $programs);
        $programs = str_replace('__CONTEXT__', $this->contextName, $programs);

        return $programs;
    }

    protected function getContextName(): string
    {
        return trim(preg_replace('%[^\\pL]+%iUum', '-', $this->contextName), '-');
    }
}
