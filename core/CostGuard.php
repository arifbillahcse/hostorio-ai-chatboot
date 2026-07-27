<?php

declare(strict_types=1);

namespace Hostorio\Core;

use Hostorio\Database\AppDatabase;
use Throwable;

/**
 * Watches spend and raises the alarm when it spikes.
 *
 * Checked before a request reaches a provider rather than after, because the
 * point is to stop the next expensive call, not to describe the last one.
 *
 * Two things it deliberately does *not* do:
 *
 *  - It does not block by default. A hard stop converts a billing problem into
 *    an outage for every customer, which is usually the worse of the two.
 *  - It does not fail closed. If the spend query itself errors, the request
 *    proceeds: refusing to answer anyone because the accounting table is
 *    unreachable would be a self-inflicted outage.
 */
final class CostGuard
{
    public const OK       = 'ok';
    public const WARNING  = 'warning';
    public const EXCEEDED = 'exceeded';

    /**
     * Current spend against the configured budgets.
     *
     * @return array{
     *     status: string,
     *     blocked: bool,
     *     hourly: array{spend: float, budget: float, ratio: float},
     *     daily: array{spend: float, budget: float, ratio: float},
     *     reasons: array<int, string>
     * }
     */
    public static function check(): array
    {
        $hourlyBudget = (float) Config::get('costs.hourly_budget', 0);
        $dailyBudget  = (float) Config::get('costs.daily_budget', 0);

        $hourlySpend = ($hourlyBudget > 0) ? self::spendSince(3600) : 0.0;
        $dailySpend  = ($dailyBudget > 0) ? self::spendSince(86400) : 0.0;

        $warnAt  = (float) Config::get('costs.warn_at', 0.8);
        $status  = self::OK;
        $reasons = [];

        foreach ([
            ['window' => 'hour', 'spend' => $hourlySpend, 'budget' => $hourlyBudget],
            ['window' => 'day',  'spend' => $dailySpend,  'budget' => $dailyBudget],
        ] as $check) {
            if ($check['budget'] <= 0) {
                continue;
            }

            $ratio = $check['spend'] / $check['budget'];

            if ($ratio >= 1.0) {
                $status    = self::EXCEEDED;
                $reasons[] = sprintf(
                    'Spend in the last %s is $%.4f, over the $%.2f budget.',
                    $check['window'],
                    $check['spend'],
                    $check['budget']
                );
            } elseif ($ratio >= $warnAt && $status !== self::EXCEEDED) {
                $status    = self::WARNING;
                $reasons[] = sprintf(
                    'Spend in the last %s is $%.4f, %d%% of the $%.2f budget.',
                    $check['window'],
                    $check['spend'],
                    (int) round($ratio * 100),
                    $check['budget']
                );
            }
        }

        $blocked = $status === self::EXCEEDED && (bool) Config::get('costs.hard_stop', false);

        if ($status !== self::OK) {
            self::alert($status, $reasons, $blocked);
        }

        return [
            'status'  => $status,
            'blocked' => $blocked,
            'hourly'  => [
                'spend'  => round($hourlySpend, 6),
                'budget' => $hourlyBudget,
                'ratio'  => $hourlyBudget > 0 ? round($hourlySpend / $hourlyBudget, 4) : 0.0,
            ],
            'daily'   => [
                'spend'  => round($dailySpend, 6),
                'budget' => $dailyBudget,
                'ratio'  => $dailyBudget > 0 ? round($dailySpend / $dailyBudget, 4) : 0.0,
            ],
            'reasons' => $reasons,
        ];
    }

    /**
     * True when a budget is exceeded *and* hard stop is enabled.
     */
    public static function shouldBlock(): bool
    {
        // Skip the query entirely when neither budget is set, so the common
        // case adds nothing to a request.
        if ((float) Config::get('costs.hourly_budget', 0) <= 0
            && (float) Config::get('costs.daily_budget', 0) <= 0) {
            return false;
        }

        return self::check()['blocked'];
    }

    /**
     * Spend recorded in the last N seconds.
     */
    private static function spendSince(int $seconds): float
    {
        try {
            $db = AppDatabase::instance();

            $row = $db->selectOne(
                sprintf(
                    'SELECT SUM(cost_usd) AS spend FROM `%s` WHERE created_at >= :since',
                    $db->table('api_costs')
                ),
                ['since' => gmdate('Y-m-d H:i:s', time() - $seconds)]
            );

            return (float) ($row['spend'] ?? 0);
        } catch (Throwable $e) {
            Logger::warning('Could not read spend for the cost guard', ['error' => $e->getMessage()]);

            // Reporting zero means "do not block", which is the safe direction:
            // an accounting outage should not take support offline.
            return 0.0;
        }
    }

    /**
     * Log an alert, rate-limited so one spike does not fill the log.
     *
     * @param array<int, string> $reasons
     */
    private static function alert(string $status, array $reasons, bool $blocked): void
    {
        $cooldown = max(60, (int) Config::get('costs.alert_cooldown', 3600));
        $key      = 'costalert:' . $status;

        if (!self::shouldAlert($key, $cooldown)) {
            return;
        }

        $context = ['status' => $status, 'reasons' => $reasons, 'blocking' => $blocked];

        if ($status === self::EXCEEDED) {
            Logger::error('SPEND ALERT: budget exceeded', $context);
        } else {
            Logger::warning('SPEND ALERT: approaching budget', $context);
        }
    }

    /**
     * Claim an alert slot. Uses the rate-limit table so the cooldown survives
     * across requests and workers.
     */
    private static function shouldAlert(string $key, int $cooldown): bool
    {
        try {
            $db = AppDatabase::instance();

            $bucket = hash('sha256', $key);

            $row = $db->selectOne(
                sprintf('SELECT expires_at FROM `%s` WHERE bucket_key = :k', $db->table('rate_limits')),
                ['k' => $bucket]
            );

            if ($row !== null && (int) $row['expires_at'] > time()) {
                return false;
            }

            $db->execute(
                sprintf(
                    'INSERT INTO `%s` (bucket_key, hits, window_start, expires_at)
                     VALUES (:k, 1, :start, :expires)
                     ON DUPLICATE KEY UPDATE hits = hits + 1, window_start = VALUES(window_start),
                                             expires_at = VALUES(expires_at)',
                    $db->table('rate_limits')
                ),
                ['k' => $bucket, 'start' => time(), 'expires' => time() + $cooldown]
            );

            return true;
        } catch (Throwable) {
            // Without storage, log every time rather than not at all — a noisy
            // log beats a silent overspend.
            return true;
        }
    }
}
