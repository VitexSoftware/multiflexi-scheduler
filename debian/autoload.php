<?php

/**
 * Autoloader for Multi Flexi Scheduler
 */

require_once '/usr/share/php/MultiFlexi/autoload.php';
require_once '/usr/share/php/Cron/autoload.php';

spl_autoload_register(function ($class) {
    // Project-specific namespace prefix
    $prefix = 'MultiFlexi\\';

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get the relative class name
    $relativeClass = substr($class, $len);

    // Replace namespace separators with directory separators
    $relativePath = str_replace('\\', '/', $relativeClass) . '.php';

    // Check if file exists locally before requiring it
    $file = __DIR__ . '/MultiFlexi/' . $relativePath;
    if (file_exists($file)) {
        require_once $file;
    }
});
