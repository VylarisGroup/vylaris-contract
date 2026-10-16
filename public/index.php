<?php

declare(strict_types=1);

// Front Controller. Das Webroot ist ausschliesslich dieses Verzeichnis (public/); Code, Konfiguration,
// Dokumentenspeicher und Backups liegen eine Ebene darüber und sind nicht erreichbar.

$root = dirname(__DIR__);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
@mkdir($root . '/storage/logs', 0750, true);
ini_set('error_log', $root . '/storage/logs/php-error.log');
error_reporting(E_ALL);
date_default_timezone_set('UTC');

if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Diese Anwendung benötigt PHP 8.3 oder neuer (installiert: ' . PHP_VERSION . ').';
    exit;
}

require $root . '/src/autoload.php';
require $root . '/src/Web/helpers.php';

(new App\Web\App($root))->run();
