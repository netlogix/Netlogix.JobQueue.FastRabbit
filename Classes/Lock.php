<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit;

class Lock
{
    private const ONE_PERCENT_OF_A_SECOND = 100000;

    /**
     * @var string[]
     */
    protected $slots = [];

    /**
     * @var string|null
     */
    protected $slotPath;

    /**
     * @var resource|null
     */
    protected $slotPointer;

    public function __construct(int $numberOfWorkers, string $lockFileDirectory)
    {
        @mkdir($lockFileDirectory, 0777, true);
        for ($i = 0; $i < $numberOfWorkers; $i++) {
            $this->slots[] = $lockFileDirectory . '/' . sha1(__CLASS__ . $i) . '.lock';
        }
    }

    public function run(callable $run)
    {
        $this->findSlot();
        try {
            return $run();
        } finally {
            @unlink($this->slotPath);
            flock($this->slotPointer, LOCK_UN);
            fclose($this->slotPointer);
        }
    }

    protected function findSlot(): void
    {
        do {
            foreach ($this->slots as $slot) {
                $fp = fopen($slot, 'w+');
                if (flock($fp, LOCK_EX | LOCK_NB)) {
                    $this->slotPath = $slot;
                    $this->slotPointer = $fp;
                    fputs($fp, (string)getmypid());
                    return;
                }
                fclose($fp);
            }
            usleep(self::ONE_PERCENT_OF_A_SECOND);
        } while (true);
    }
}
