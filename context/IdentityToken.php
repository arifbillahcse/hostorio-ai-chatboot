<?php

declare(strict_types=1);

namespace Hostorio\Context;

use Hostorio\Core\Config;
use Hostorio\Core\Logger;

/**
 * Signed proof that a visitor is a particular WHMCS customer.
 *
 * The problem this solves: the chat widget runs in the browser, so anything it
 * sends can be edited. If the backend trusted a `customer_id` field, changing
 * one number would expose another customer's services, tickets and invoices.
 *
 * Instead the hosting company mints a token server-side, inside a page that has
 * already authenticated the visitor:
 *
 *     // In a WHMCS client-area template:
 *     $token = IdentityToken::issue((int) $client->id);
 *
 * The token is an HMAC over the customer id and an expiry, keyed by APP_KEY.
 * The browser can read it but cannot forge one for a different id without the
 * key, which never leaves the server.
 *
 * Format: v1.<customerId>.<expiresAt>.<base64url hmac>
 */
final class IdentityToken
{
    private const VERSION = 'v1';

    /**
     * Mint a token for a customer.
     *
     * @param int|null $ttl seconds; falls back to the configured default
     */
    public static function issue(int $customerId, ?int $ttl = null): string
    {
        if ($customerId < 1) {
            throw new \InvalidArgumentException('A customer id must be a positive integer.');
        }

        $ttl ??= (int) Config::get('context.identity.token_ttl', 3600);
        $expiresAt = time() + max(60, $ttl);

        $payload = self::VERSION . '.' . $customerId . '.' . $expiresAt;

        return $payload . '.' . self::sign($payload);
    }

    /**
     * Verify a token and return the customer id it proves, or null.
     *
     * Returns null for every failure mode rather than distinguishing them: a
     * caller cannot act differently on "expired" versus "forged", and telling
     * them apart only helps someone probing the endpoint.
     */
    public static function verify(string $token): ?int
    {
        $secret = self::secret();

        if ($secret === '') {
            // Failing closed matters more than convenience here — without a key
            // every token would verify against an empty secret.
            Logger::error('APP_KEY is not set; identity tokens cannot be verified');

            return null;
        }

        $parts = explode('.', trim($token));

        if (count($parts) !== 4) {
            return null;
        }

        [$version, $customerId, $expiresAt, $signature] = $parts;

        if ($version !== self::VERSION) {
            return null;
        }

        if (!ctype_digit($customerId) || !ctype_digit($expiresAt)) {
            return null;
        }

        $payload = $version . '.' . $customerId . '.' . $expiresAt;

        // Constant-time comparison — a timing-variable check would leak the
        // expected signature one byte at a time.
        if (!hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        if ((int) $expiresAt < time()) {
            return null;
        }

        $id = (int) $customerId;

        return $id > 0 ? $id : null;
    }

    private static function sign(string $payload): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $payload, self::secret(), true));
    }

    private static function secret(): string
    {
        return (string) Config::get('app.key', '');
    }

    private static function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
