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

require_once '/usr/share/php/Composer/InstalledVersions.php';

(function (): void {
    $versions = [];
    foreach (\Composer\InstalledVersions::getAllRawData() as $d) {
        $versions = array_merge($versions, $d['versions'] ?? []);
    }
    $name    = defined('APP_NAME')    ? APP_NAME    : 'unknown';
    $version = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
    $versions[$name] = ['pretty_version' => $version, 'version' => $version,
        'reference' => null, 'type' => 'library', 'install_path' => __DIR__,
        'aliases' => [], 'dev_requirement' => false];
    \Composer\InstalledVersions::reload([
        'root' => ['name' => $name, 'pretty_version' => $version, 'version' => $version,
            'reference' => null, 'type' => 'project', 'install_path' => __DIR__,
            'aliases' => [], 'dev' => false],
        'versions' => $versions,
    ]);
})();
