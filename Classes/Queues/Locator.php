<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\FastRabbit\Queues;

interface Locator extends \Iterator
{
    public function current(): string;
}
