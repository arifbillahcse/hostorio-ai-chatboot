<?php

declare(strict_types=1);

namespace Hostorio\Admin;

use Hostorio\Core\Config;
use Hostorio\Core\Logger;
use Hostorio\Core\Request;
use Hostorio\Database\AppDatabase;
use Throwable;

/**
 * Authentication for the admin panel.
 *
 * The panel reads customer conversations, live billing context and provider API
 * keys, so it is the highest-value target in the application. The controls
 * here are the ones that matter for a single-password panel on shared hosting:
 *
 *  - the password is only ever compared as a hash, from .env, never the database
 *    (an attacker with SQL write access should not be able to grant themselves
 *    a login);
 *  - failed attempts are throttled per IP, because a single password with no
 *    lockout is a guessing game an attacker always eventually wins;
 *  - the session id is regenerated on login, so a session fixed before login
 *    cannot be reused after it;
 *  - every state-changing request carries a CSRF token, since the panel is
 *    cookie-authenticated and otherwise a malicious page could act as the
 *    logged-in admin.
 */
final class AdminAuth
{
    private const SESSION_KEY   = 'hoai_admin';
    private const CSRF_KEY      = 'hoai_admin_csrf';
    private const LOCKOUT_LIMIT = 5;
    private const LOCKOUT_SECS  = 900; // 15 minutes

    /**
     * Start the session with cookie flags a panel like this needs.
     *
     * Called before anything reads session state. Idempotent.
     */
    public static function startSession(Request $request): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = self::isHttps($request);

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            // Lax rather than Strict: the panel is navigated to from bookmarks
            // and links, and Strict would drop the session on those. CSRF is
            // handled by tokens, not by the cookie policy alone.
            'samesite' => 'Lax',
        ]);

        session_name('hoai_admin_session');

        @session_start();
    }

    private static function isHttps(Request $request): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        // Behind a terminating proxy the connection to PHP is plain HTTP.
        return strtolower((string) $request->header('x-forwarded-proto', '')) === 'https';
    }

    /** Is a panel password configured at all? */
    public static function isConfigured(): bool
    {
        return self::hash() !== '';
    }

    private static function hash(): string
    {
        return (string) Config::get('security.admin_password_hash', '');
    }

    public static function isLoggedIn(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE
            && !empty($_SESSION[self::SESSION_KEY]['authenticated']);
    }

    /**
     * Attempt a login.
     *
     * @return array{ok: bool, message: string}
     */
    public static function attempt(string $password, Request $request): array
    {
        if (!self::isConfigured()) {
            return [
                'ok' => false,
                'message' => 'No admin password is configured. Set ADMIN_PASSWORD_HASH in your .env file.',
            ];
        }

        $lock = self::lockState($request->ip);

        if ($lock['locked']) {
            Logger::warning('Admin login blocked by lockout', ['ip' => $request->ip]);

            return [
                'ok' => false,
                'message' => sprintf(
                    'Too many failed attempts. Try again in about %d minutes.',
                    max(1, (int) ceil($lock['retry_after'] / 60))
                ),
            ];
        }

        if ($password === '' || !password_verify($password, self::hash())) {
            self::recordFailure($request->ip);

            Logger::warning('Admin login failed', ['ip' => $request->ip]);

            // Deliberately vague: distinguishing "no such user" from "wrong
            // password" would confirm nothing useful to a legitimate admin and
            // something useful to an attacker.
            return ['ok' => false, 'message' => 'Incorrect password.'];
        }

        self::clearFailures($request->ip);

        // Defeats session fixation: any id an attacker planted before login is
        // discarded here.
        @session_regenerate_id(true);

        $_SESSION[self::SESSION_KEY] = [
            'authenticated' => true,
            'since'         => time(),
            'ip'            => $request->ip,
        ];

        Logger::info('Admin logged in', ['ip' => $request->ip]);

        return ['ok' => true, 'message' => ''];
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        @session_destroy();
    }

    // ── CSRF ─────────────────────────────────────────────────────────────────

    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }

        if (empty($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::CSRF_KEY];
    }

    public static function verifyCsrf(Request $request): bool
    {
        $token = $request->input('_csrf', '');

        if (!is_string($token) || $token === '') {
            return false;
        }

        $expected = $_SESSION[self::CSRF_KEY] ?? '';

        return is_string($expected) && $expected !== '' && hash_equals($expected, $token);
    }

    // ── Lockout ──────────────────────────────────────────────────────────────

    /**
     * Failed-attempt state for an IP.
     *
     * Reuses the rate-limit table rather than adding another; the shape is the
     * same and it is already pruned.
     *
     * @return array{locked: bool, retry_after: int, failures: int}
     */
    private static function lockState(string $ip): array
    {
        try {
            $db  = AppDatabase::instance();
            $row = $db->selectOne(
                sprintf('SELECT hits, expires_at FROM `%s` WHERE bucket_key = :k', $db->table('rate_limits')),
                ['k' => self::lockKey($ip)]
            );
        } catch (Throwable) {
            // Fail open on a storage problem: locking every admin out because
            // the database hiccuped would be worse than the risk it prevents,
            // and the password check itself still stands.
            return ['locked' => false, 'retry_after' => 0, 'failures' => 0];
        }

        if ($row === null) {
            return ['locked' => false, 'retry_after' => 0, 'failures' => 0];
        }

        $failures  = (int) $row['hits'];
        $expiresAt = (int) $row['expires_at'];

        if ($expiresAt <= time()) {
            return ['locked' => false, 'retry_after' => 0, 'failures' => 0];
        }

        return [
            'locked'      => $failures >= self::LOCKOUT_LIMIT,
            'retry_after' => max(0, $expiresAt - time()),
            'failures'    => $failures,
        ];
    }

    private static function recordFailure(string $ip): void
    {
        try {
            $db = AppDatabase::instance();

            $db->execute(
                sprintf(
                    'INSERT INTO `%s` (bucket_key, hits, window_start, expires_at)
                     VALUES (:k, 1, :start, :expires)
                     ON DUPLICATE KEY UPDATE hits = hits + 1, expires_at = VALUES(expires_at)',
                    $db->table('rate_limits')
                ),
                [
                    'k'       => self::lockKey($ip),
                    'start'   => time(),
                    'expires' => time() + self::LOCKOUT_SECS,
                ]
            );
        } catch (Throwable $e) {
            Logger::error('Could not record failed admin login', ['error' => $e->getMessage()]);
        }
    }

    private static function clearFailures(string $ip): void
    {
        try {
            $db = AppDatabase::instance();
            $db->execute(
                sprintf('DELETE FROM `%s` WHERE bucket_key = :k', $db->table('rate_limits')),
                ['k' => self::lockKey($ip)]
            );
        } catch (Throwable) {
            // Not important enough to surface.
        }
    }

    private static function lockKey(string $ip): string
    {
        return hash('sha256', 'admin-login|' . $ip);
    }
}
