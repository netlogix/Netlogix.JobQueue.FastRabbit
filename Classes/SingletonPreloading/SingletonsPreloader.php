<?php

namespace Netlogix\JobQueue\FastRabbit\SingletonPreloading;

interface SingletonsPreloader
{
    public function collect(): void;
}
