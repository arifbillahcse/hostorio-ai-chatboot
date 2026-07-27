<?php

declare(strict_types=1);

namespace Hostorio\Core;

use Hostorio\Database\AppDatabase;

/**
 * Fixed-window rate limiter backed by the application database.
 *
 * Database-backed rather than file-backed because PHP-FPM workers on shared
 * hosting do not share memory, and a MySQL upsert is atomic where a
 * read-modify-write on a file is not.
 */
final class RateLimiter
{
    /**
     * Record a hit and report whether the caller is still within its quota.
     *
     * @param string $identity stable key for the caller (customer id or IP)
     */
    public static function attempt(string $identity): RateLimitResult
    {
        if (!Config::get('rate_limit.enabled', true)) {
            return new RateLimitResult(true, 0, 0, 0);
        }

        $maxRequests = max(1, (int) Config::get('rate_limit.max_requests', 20));
        $windowSize  = max(1, (int) Config::get('rate_limit.window', 60));

        $now = time();
        // Fixed window: everyone in the same window shares a bucket key.
        $windowStart = $now - ($now % $windowSize);
        $key         = hash('sha256', $identity . '|' . $windowStart);

        $db = AppDatabase::instance();

        try {
            // Atomic upsert: the counter increments server-side, so two
            // concurrent workers cannot both read the same stale value.
            $db->execute(
                sprintf(
                    'INSERT INTO `%s` (bucket_key, hits, window_start, expires_at)
                     VALUES (:key, 1, :window_start, :expires_at)
                     ON DUPLICATE KEY UPDATE hits = hits + 1',
                    $db->table('rate_limits')
                ),
                [
                    'key'          => $key,
                    'window_start' => $windowStart,
                    'expires_at'   => $windowStart + ($windowSize * 2),
                ]
            );

            $row = $db->selectOne(
                sprintf('SELECT hits FROM `%s` WHERE bucket_key = :key', $db->table('rate_limits')),
                ['key' => $key]
            );

            $hits = (int) ($row['hits'] ?? 1);
        } catch (\Throwable $e) {
            // Fail open. A limiter outage must not take the chatbot down, but
            // it must be visible in the logs.
            Logger::error('Rate limiter unavailable, allowing request', ['error' => $e->getMessage()]);

            return new RateLimitResult(true, 0, 0, 0);
        }

        self::pruneOccasionally($db, $now);

        $remaining  = max(0, $maxRequests - $hits);
        $retryAfter = ($windowStart + $windowSize) - $now;

        return new RateLimitResult(
            allowed: $hits <= $maxRequests,
            limit: $maxRequests,
            remaining: $remaining,
            retryAfter: max(1, $retryAfter)
        );
    }

    /**
     * Drop expired buckets so the table stays small. Probabilistic, for the
     * same reason as log pruning — no cron dependency on shared hosting.
     */
    private static function pruneOccasionally(AppDatabase $db, int $now): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }

        try {
            $db->execute(
                sprintf('DELETE FROM `%s` WHERE expires_at < :now', $db->table('rate_limits')),
                ['now' => $now]
            );
        } catch (\Throwable) {
            // Cleanup is best-effort; never block a request over it.
        }
    }
}
