<?php

declare(strict_types=1);

use Hostorio\Core\Env;

/**
 * Rate limiting. Merged into Config under the `rate_limit.` prefix.
 *
 * Applied per identity — the WHMCS customer id when known, otherwise the
 * client IP. Guards both the hosting account's resources and the LLM bill.
 */
return [
    'enabled'      => Env::bool('RATE_LIMIT_ENABLED', true),
    'max_requests' => Env::int('RATE_LIMIT_MAX_REQUESTS', 20),
    'window'       => Env::int('RATE_LIMIT_WINDOW', 60),

    // POST /api/identity/login checks a real WHMCS password, so it gets its
    // own, much tighter budget to blunt brute-force and credential-stuffing
    // attempts against customer accounts.
    'login_max_requests' => Env::int('LOGIN_RATE_LIMIT_MAX', 5),
    'login_window'       => Env::int('LOGIN_RATE_LIMIT_WINDOW', 900),
];
