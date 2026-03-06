<?php

/**
 * Autoloader for Multi Flexi Scheduler
 */

spl_autoload_register(function ($class) {
    // Project-specific namespace prefix
    $prefix = 'Multiflexi\\';

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    // Get the relative class name
    $relative_class = substr($class, $len);
    
    // Replace namespace separators with directory separators
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative_class) . '.php';

    // If the file exists, load it
    if (file_exists($file)) {
        require $file;
    }
});
