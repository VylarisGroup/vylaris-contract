<?php

declare(strict_types=1);

// Aufruf: php tests/run.php [Filter]   (Umgebung: TEST_ENV_FILE=/pfad/.env.test)
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../src/autoload.php';
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Tests\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Tests\TestCase;

$filter = $argv[1] ?? '';
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (str_ends_with($f->getFilename(), 'Test.php')) {
        $files[] = $f->getPathname();
    }
}
sort($files);

$passed = 0;
$failed = 0;
$assertions = 0;
$failures = [];

foreach ($files as $file) {
    $rel = substr($file, strlen(__DIR__) + 1, -4);
    $class = 'Tests\\' . str_replace('/', '\\', $rel);
    if (!class_exists($class) || !is_subclass_of($class, TestCase::class)) {
        continue;
    }
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        $name = $class . '::' . $method;
        if ($filter !== '' && !str_contains($name, $filter)) {
            continue;
        }
        $t = new $class();
        try {
            $t->bootDatabase();
            $t->setUp();
            $t->$method();
            $t->tearDown();
            $passed++;
            echo '.';
        } catch (Throwable $e) {
            $failed++;
            echo 'F';
            $failures[] = [$name, $e];
        } finally {
            $assertions += $t->assertions;
            $t->rollbackDatabase();
        }
    }
}

echo "\n\n";
foreach ($failures as [$name, $e]) {
    echo "FEHLER {$name}\n  " . $e->getMessage() . "\n  (" . basename($e->getFile()) . ':' . $e->getLine() . ")\n\n";
}
printf("%d Tests, %d bestanden, %d fehlgeschlagen, %d Assertions\n", $passed + $failed, $passed, $failed, $assertions);
exit($failed === 0 ? 0 : 1);
