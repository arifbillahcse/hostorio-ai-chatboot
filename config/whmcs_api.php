<?php

declare(strict_types=1);

use Hostorio\Core\Env;

/**
 * WHMCS remote API credentials. Merged into Config under the `whmcs_api.`
 * prefix.
 *
 * Distinct from `database.whmcs` (a read-only MySQL connection used for
 * pulling account context): validating a password has to go through WHMCS's
 * own API, never a raw SQL query. WHMCS stores the password hashed with its
 * own, version-dependent algorithm, and re-deriving that here would silently
 * drift out of sync on a WHMCS upgrade — WHMCS ships ValidateLogin precisely
 * so nobody has to.
 */
return [
    'url'        => Env::get('WHMCS_API_URL', ''),
    'identifier' => Env::get('WHMCS_API_IDENTIFIER', ''),
    'secret'     => Env::get('WHMCS_API_SECRET', ''),
];
