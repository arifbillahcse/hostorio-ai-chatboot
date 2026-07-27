<?php

declare(strict_types=1);

use Hostorio\Core\Env;

/**
 * Security settings. Merged into Config under the `security.` prefix.
 */
return [
    /*
     * Origins permitted to call the chat API from a browser.
     * Defaults to the configured APP_URL only. Add the customer's site here
     * if the widget is embedded on a different hostname.
     */
    'allowed_origins' => array_values(array_filter([
        rtrim((string) Env::get('APP_URL', ''), '/'),
    ])),

    /*
     * Proxy IPs whose X-Forwarded-For header may be trusted when resolving the
     * client IP. Leave empty on standard cPanel hosting — an untrusted
     * forwarded header would let a caller trivially bypass rate limiting.
     */
    'trusted_proxies' => [],

    /* Maximum characters accepted in a single user message. */
    'max_message_length' => 4000,

    /* Admin panel credentials (Phase 7). */
    'admin_password_hash' => Env::get('ADMIN_PASSWORD_HASH', ''),
];
