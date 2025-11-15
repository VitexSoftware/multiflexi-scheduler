<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap File
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Define test constants
define('EASE_APPNAME', 'MultiFlexi Scheduler Tests');
define('EASE_LOGGER', 'console');

// Require Composer autoloader
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

// Set timezone for tests
date_default_timezone_set('UTC');