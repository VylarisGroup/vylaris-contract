<?php

declare(strict_types=1);

// CLI: php bin/migrate.php [--verify]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;

$root = dirname(__DIR__);
$config = Config::fromFile(getenv('APP_ENV_FILE') ?: $root . '/.env');
$db = Database::connect(
    $config->get('DB_HOST', '127.0.0.1'),
    $config->int('DB_PORT', 3306),
    $config->get('DB_NAME'),
    $config->get('DB_USER'),
    $config->get('DB_PASS'),
);
$migrator = new Migrator($db, $root . '/database/migrations');

if (in_array('--verify', $argv, true)) {
    $errors = $migrator->verify();
    echo $errors === [] ? "Migrationen konsistent.\n" : implode("\n", $errors) . "\n";
    exit($errors === [] ? 0 : 1);
}

$result = $migrator->migrate();
echo $result['applied'] === [] ? "Nichts zu tun.\n" : 'Angewendet: ' . implode(', ', $result['applied']) . "\n";
foreach ($result['warnings'] as $w) {
    fwrite(STDERR, "WARNUNG: {$w}\n");
}
