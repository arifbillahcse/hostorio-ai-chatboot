<?php

declare(strict_types=1);

/**
 * Front controller — the single entry point for every chat API request.
 *
 * Point the document root here if the host allows it. If not, the root
 * .htaccess rewrites incoming requests into this file, so both layouts work.
 */

/*
 * PHP's built-in server routes every request through this file, including
 * requests for the widget's static assets. Returning false hands those back to
 * the server to serve directly, which is what Apache does via .htaccess in
 * production. Restricted to non-PHP files so no source can be reached this way.
 *
 * Only applies to `php -S`; ignored under Apache, nginx or FPM.
 */
if (PHP_SAPI === 'cli-server') {
    $requested = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

    if (is_string($requested) && !str_ends_with(strtolower($requested), '.php')) {
        $candidate = __DIR__ . '/' . ltrim($requested, '/');

        if (is_file($candidate)) {
            return false;
        }
    }
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Hostorio\Api\ChatController;
use Hostorio\Api\HealthController;
use Hostorio\Api\WidgetController;
use Hostorio\Core\Request;
use Hostorio\Core\Router;

$request = Request::capture();
$router  = new Router();

$health = new HealthController();
$chat   = new ChatController();
$widget = new WidgetController();

$router->get('/health', $health->ping(...));
$router->get('/api/health', $health->ping(...));
$router->get('/api/health/diagnostics', $health->diagnostics(...));

$router->get('/api/widget/config', $widget->config(...));

$router->post('/api/chat', $chat->send(...));

$router->dispatch($request)->send();
