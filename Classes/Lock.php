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

    public function __construct(int $numberOfWorkers, string $temporaryDirectory)
    {
        for ($i = 0; $i < $numberOfWorkers; $i++) {
            $this->slots[] = $temporaryDirectory . '/' . sha1(__CLASS__ . $i) . '.lock';
        }
    }

    public function run(callable $run)
    {
        $lock = $this->findSlot();
        try {
            return $run();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function findSlot()
    {
        do {
            foreach ($this->slots as $slot) {
                $fp = fopen($slot, 'w+');
                if (flock($fp, LOCK_EX | LOCK_NB)) {
                    return $fp;
                }
                fclose($fp);
            }
            usleep(self::ONE_PERCENT_OF_A_SECOND);
        } while (true);
    }
}
