<?php

declare(strict_types=1);

/**
 * Front controller — the single entry point for every chat API request.
 *
 * Point the document root here if the host allows it. If not, the root
 * .htaccess rewrites incoming requests into this file, so both layouts work.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Hostorio\Api\ChatController;
use Hostorio\Api\HealthController;
use Hostorio\Core\Request;
use Hostorio\Core\Router;

$request = Request::capture();
$router  = new Router();

$health = new HealthController();
$chat   = new ChatController();

$router->get('/health', $health->ping(...));
$router->get('/api/health', $health->ping(...));
$router->get('/api/health/diagnostics', $health->diagnostics(...));

$router->post('/api/chat', $chat->send(...));

$router->dispatch($request)->send();
