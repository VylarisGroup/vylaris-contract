<?php

declare(strict_types=1);

// Eigener PSR-4-Autoloader: Die Anwendung braucht zur Laufzeit weder Composer noch ein vendor-Verzeichnis.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
