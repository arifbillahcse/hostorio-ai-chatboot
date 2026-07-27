<?php

declare(strict_types=1);

use Hostorio\Core\Env;

/**
 * Logging settings. Merged into Config under the `logging.` prefix.
 *
 * Logs live outside the document root by default. If the app is installed
 * directly inside public_html, storage/.htaccess denies web access as a
 * second layer of protection.
 */
return [
    'level'          => strtolower((string) Env::get('LOG_LEVEL', 'info')),
    'path'           => HOAI_ROOT . '/storage/logs',
    'retention_days' => Env::int('LOG_RETENTION_DAYS', 30),
];
