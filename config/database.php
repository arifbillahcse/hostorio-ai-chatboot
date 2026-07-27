<?php

declare(strict_types=1);

use Hostorio\Core\Env;

/**
 * Database connection settings. Merged into Config under the `database.` prefix.
 *
 * Only the `app` connection is writable. The WordPress and WHMCS connections
 * are enforced read-only in code (see Hostorio\Database\Connection) and should
 * additionally be given SELECT-only MySQL users at the server level.
 *
 * Leaving a `name` blank disables that connector — the app boots normally and
 * the health check reports it as "not configured".
 */
return [
    'app' => [
        'host'    => Env::get('DB_HOST', 'localhost'),
        'port'    => Env::int('DB_PORT', 3306),
        'name'    => Env::get('DB_NAME', ''),
        'user'    => Env::get('DB_USER', ''),
        'pass'    => Env::get('DB_PASS', ''),
        'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
        'prefix'  => Env::get('DB_PREFIX', 'hoai_'),
    ],

    'wordpress' => [
        'host'         => Env::get('WP_DB_HOST', 'localhost'),
        'port'         => Env::int('WP_DB_PORT', 3306),
        'name'         => Env::get('WP_DB_NAME', ''),
        'user'         => Env::get('WP_DB_USER', ''),
        'pass'         => Env::get('WP_DB_PASS', ''),
        'charset'      => Env::get('WP_DB_CHARSET', 'utf8mb4'),
        'table_prefix' => Env::get('WP_TABLE_PREFIX', 'wp_'),
    ],

    'whmcs' => [
        'host'    => Env::get('WHMCS_DB_HOST', 'localhost'),
        'port'    => Env::int('WHMCS_DB_PORT', 3306),
        'name'    => Env::get('WHMCS_DB_NAME', ''),
        'user'    => Env::get('WHMCS_DB_USER', ''),
        'pass'    => Env::get('WHMCS_DB_PASS', ''),
        'charset' => Env::get('WHMCS_DB_CHARSET', 'utf8mb4'),
    ],
];
