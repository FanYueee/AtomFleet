<?php
declare(strict_types=1);

spl_autoload_register(static function ($class) {
    $prefix = 'AtomFleet\\Whmcs\\Proxmox\\';
    $prefixLength = strlen($prefix);

    if (strncmp($class, $prefix, $prefixLength) !== 0) {
        return;
    }

    $relativeClass = substr($class, $prefixLength);
    $file = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
