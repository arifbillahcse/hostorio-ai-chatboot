<?php

declare(strict_types=1);

use Hostorio\Core\Env;

/**
 * Admin panel. Merged into Config under the `admin.` prefix.
 */
return [
    /*
     * Layer database-stored settings over the file configuration at bootstrap.
     *
     * Costs one indexed query per request. Turn it off to pin the application
     * strictly to .env — useful if you manage configuration by deployment and
     * want the panel to be read-only in practice.
     */
    'db_settings' => Env::bool('ADMIN_DB_SETTINGS', true),
];
