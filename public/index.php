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

    if (is_string($requested)) {
        $lower = strtolower($requested);

        // The installer is a real page, not an application route — Apache
        // serves it directly and the built-in server must do the same.
        if ($lower === '/install.php' && is_file(__DIR__ . '/install.php')) {
            require __DIR__ . '/install.php';

            return;
        }

        if (!str_ends_with($lower, '.php')) {
            $candidate = __DIR__ . '/' . ltrim($requested, '/');

            if (is_file($candidate)) {
                return false;
            }
        }
    }
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Hostorio\Api\ChatController;
use Hostorio\Api\HealthController;
use Hostorio\Api\IdentityController;
use Hostorio\Api\WidgetController;
use Hostorio\Core\Request;
use Hostorio\Core\Router;

$request = Request::capture();

/*
 * The admin panel is handled ahead of the JSON router.
 *
 * It needs prefix matching (/admin, /admin/knowledge, /admin/conversation/export)
 * and it renders HTML rather than JSON, so folding it into the exact-match
 * router would mean teaching that router two things it otherwise does not need
 * to know.
 */
if ($request->path === '/admin' || str_starts_with($request->path, '/admin/')) {
    $page = trim(substr($request->path, strlen('/admin')), '/');

    (new Hostorio\Admin\AdminController())->handle($request, $page)->send();

    return;
}

$router = new Router();

$health   = new HealthController();
$chat     = new ChatController();
$widget   = new WidgetController();
$identity = new IdentityController();

$router->get('/health', $health->ping(...));
$router->get('/api/health', $health->ping(...));
$router->get('/api/health/diagnostics', $health->diagnostics(...));

$router->get('/api/widget/config', $widget->config(...));

$router->post('/api/chat', $chat->send(...));
$router->get('/api/chat/history', $chat->history(...));

$router->post('/api/identity/token', $identity->token(...));

$router->dispatch($request)->send();
