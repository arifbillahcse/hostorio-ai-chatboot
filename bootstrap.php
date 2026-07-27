<?php

declare(strict_types=1);

/**
 * Application bootstrap.
 *
 * Every entry point (public/index.php, admin, CLI tools) includes this file and
 * nothing else. It registers the autoloader, loads configuration, and installs
 * error handling — in that order, because error handling depends on config.
 */

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Hostorio AI Chatbot requires PHP 8.1 or newer. Detected: ' . PHP_VERSION);
}

define('HOAI_ROOT', __DIR__);
define('HOAI_START', microtime(true));

/*
 * PSR-4 style autoloader.
 *
 * Hostorio\Core\Logger        -> core/Logger.php
 * Hostorio\Database\AppDatabase -> database/AppDatabase.php
 * Hostorio\Api\ChatController -> api/ChatController.php
 *
 * Written by hand rather than pulled from Composer so the package installs by
 * upload alone on hosting without shell access.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Hostorio\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative  = substr($class, strlen($prefix));
    $separator = strpos($relative, '\\');

    if ($separator === false) {
        // Single-segment class (Hostorio\Foo) — no sub-namespace directory.
        $path = HOAI_ROOT . '/core/' . $relative . '.php';
    } else {
        $directory = strtolower(substr($relative, 0, $separator));
        $remainder = str_replace('\\', '/', substr($relative, $separator + 1));
        $path      = HOAI_ROOT . '/' . $directory . '/' . $remainder . '.php';
    }

    if (is_file($path)) {
        require_once $path;
    }
});

use Hostorio\Core\Config;
use Hostorio\Core\Env;
use Hostorio\Core\Logger;

/*
 * Load environment. The .env file is looked for one level above the app root
 * first — the recommended location on cPanel, since anything above
 * public_html is unreachable over HTTP even if .htaccess protection fails.
 */
$envCandidates = [
    dirname(HOAI_ROOT) . '/.hoai.env',
    HOAI_ROOT . '/.env',
];

foreach ($envCandidates as $candidate) {
    if (is_readable($candidate)) {
        Env::load($candidate);
        break;
    }
}

if (!Env::isLoaded()) {
    // Load nothing but mark as attempted, so config defaults still apply and
    // the health check can report "no .env found" rather than crashing.
    Env::load(HOAI_ROOT . '/.env');
}

/*
 * Assemble configuration from config/*.php. Each file returns an array and is
 * keyed by its filename: config/database.php -> Config::get('database.…').
 */
$config = [];

foreach (glob(HOAI_ROOT . '/config/*.php') ?: [] as $file) {
    $key = basename($file, '.php');
    /** @var array<string, mixed> $values */
    $values      = require $file;
    $config[$key] = $values;
}

Config::set($config);

date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

/*
 * Error handling.
 *
 * Display is always off — a PHP notice rendered into a JSON response corrupts
 * it, and a stack trace on a customer-facing page leaks paths and credentials.
 * Everything is routed to the logger instead.
 */
$debug = (bool) Config::get('app.debug', false);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_STRICT);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    Logger::warning('PHP error', [
        'severity' => $severity,
        'message'  => $message,
        'file'     => $file,
        'line'     => $line,
    ]);

    return true;
});

set_exception_handler(static function (Throwable $e): void {
    Logger::error('Uncaught exception', [
        'exception' => $e::class,
        'message'   => $e->getMessage(),
        'file'      => $e->getFile(),
        'line'      => $e->getLine(),
    ]);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'ok'         => false,
        'error'      => ['code' => 'server_error', 'message' => 'An internal error occurred.'],
        'request_id' => Logger::requestId(),
    ]);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    Logger::error('Fatal error', [
        'message' => $error['message'],
        'file'    => $error['file'],
        'line'    => $error['line'],
    ]);
});
