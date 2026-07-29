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

    /*
     * Trust Cloudflare's CF-Connecting-IP header as the real client IP.
     *
     * When a site sits behind Cloudflare, REMOTE_ADDR is one of Cloudflare's
     * own edge IPs, not the visitor's — and it can differ between requests
     * from the very same browser tab as Cloudflare routes across edge nodes.
     * Anonymous conversation ownership and rate limiting are both keyed on
     * this IP, so without this, a reload or a new tab can silently look like
     * a different visitor and the chat appears to have reset.
     *
     * Only enable this if the origin server cannot be reached directly,
     * bypassing Cloudflare — otherwise anyone can forge this header straight
     * to the origin and impersonate any IP. Cloudflare's "Authenticated
     * Origin Pulls" feature (or a firewall rule allowing only Cloudflare's
     * published IP ranges) provides that guarantee.
     */
    'trust_cloudflare' => Env::bool('TRUST_CLOUDFLARE_IP', false),

    /* Maximum characters accepted in a single user message. */
    'max_message_length' => 4000,

    /* Admin panel credentials (Phase 7). */
    'admin_password_hash' => Env::get('ADMIN_PASSWORD_HASH', ''),
];
