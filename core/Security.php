<?php

declare(strict_types=1);

namespace Hostorio\Core;

/**
 * Input sanitization and output escaping helpers.
 *
 * Note on scope: SQL safety is handled by prepared statements in the Database
 * layer, not here. These helpers normalise untrusted text before it reaches a
 * prompt, a log line, or an HTML template.
 */
final class Security
{
    /** Hard ceiling on a single user message, in characters. */
    public const MAX_MESSAGE_LENGTH = 4000;

    /**
     * Clean a free-text user message before it enters the pipeline.
     *
     * Strips control characters (which can be used to smuggle instructions past
     * a naive prompt template), normalises whitespace, and enforces a length
     * cap so one request cannot blow up token spend.
     */
    public static function sanitizeMessage(string $input, int $maxLength = self::MAX_MESSAGE_LENGTH): string
    {
        // Drop invalid UTF-8 sequences outright.
        $clean = mb_convert_encoding($input, 'UTF-8', 'UTF-8');

        // Remove C0/C1 control characters except tab and newline.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}]/u', '', $clean) ?? '';

        // Collapse runs of blank lines; keep paragraph structure readable.
        $clean = preg_replace("/(\r\n|\r)/", "\n", $clean) ?? '';
        $clean = preg_replace("/\n{3,}/", "\n\n", $clean) ?? '';

        $clean = trim($clean);

        if (mb_strlen($clean, 'UTF-8') > $maxLength) {
            $clean = mb_substr($clean, 0, $maxLength, 'UTF-8');
        }

        return $clean;
    }

    /**
     * Sanitize a short identifier (session id, provider name, tenant key).
     * Anything outside [A-Za-z0-9_-] is dropped.
     */
    public static function sanitizeIdentifier(string $input, int $maxLength = 64): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $input) ?? '';

        return substr($clean, 0, $maxLength);
    }

    /**
     * Cast an untrusted value to a positive integer id, or null if invalid.
     */
    public static function sanitizeId(mixed $input): ?int
    {
        if (!is_scalar($input)) {
            return null;
        }

        $value = filter_var((string) $input, FILTER_VALIDATE_INT);

        return ($value === false || $value < 1) ? null : $value;
    }

    public static function sanitizeEmail(string $input): ?string
    {
        $clean = filter_var(trim($input), FILTER_VALIDATE_EMAIL);

        return $clean === false ? null : $clean;
    }

    /**
     * Escape for HTML output (admin panel, widget rendering).
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Resolve the client IP, honouring proxy headers only when the request
     * actually arrived from a trusted proxy. Spoofable headers are ignored
     * otherwise — rate limiting depends on this being hard to forge.
     */
    public static function clientIp(): string
    {
        // Checked first and unconditionally on the header's presence (not on
        // REMOTE_ADDR belonging to a known Cloudflare range): Cloudflare's
        // edge IPs change over time and are numerous, so allow-listing them
        // is brittle. The opt-in config flag is the actual safety gate here
        // — see config/security.php for why it defaults to off.
        if ((bool) Config::get('security.trust_cloudflare', false)) {
            $cloudflareIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';

            if (is_string($cloudflareIp) && $cloudflareIp !== ''
                && filter_var($cloudflareIp, FILTER_VALIDATE_IP) !== false) {
                return $cloudflareIp;
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        /** @var array<int, string> $trustedProxies */
        $trustedProxies = (array) Config::get('security.trusted_proxies', []);

        if ($trustedProxies !== [] && in_array($remote, $trustedProxies, true)) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);

                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
    }

    /**
     * Constant-time comparison for secrets (admin tokens, webhook signatures).
     */
    public static function secureCompare(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    /**
     * Generate a CSRF token bound to the current session.
     */
    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }

        if (empty($_SESSION['_hoai_csrf'])) {
            $_SESSION['_hoai_csrf'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['_hoai_csrf'];
    }

    public static function verifyCsrfToken(string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['_hoai_csrf'])) {
            return false;
        }

        return hash_equals((string) $_SESSION['_hoai_csrf'], $token);
    }
}
