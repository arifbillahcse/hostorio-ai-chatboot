<?php

declare(strict_types=1);

use Hostorio\Core\Env;

/**
 * Application-level settings. Merged into Config under the `app.` prefix.
 */
return [
    'name'     => Env::get('APP_NAME', 'Hostorio AI Chatbot'),
    'env'      => Env::get('APP_ENV', 'production'),
    'debug'    => Env::bool('APP_DEBUG', false),
    'url'      => rtrim((string) Env::get('APP_URL', ''), '/'),
    'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
    'key'      => Env::get('APP_KEY', ''),
    'version'  => '0.1.0-phase1',
];
