<?php
declare(strict_types=1);

$autoloaders = [
    __DIR__ . '/../../autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
    getcwd() . '/Packages/Libraries/autoload.php'
];
foreach ($autoloaders as $autoloader) {
    if (file_exists($autoloader)) {
        require_once $autoloader;
        return;
    }
}
throw new RuntimeException('No autoloader found!');
