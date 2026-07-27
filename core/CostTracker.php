<?php

declare(strict_types=1);

namespace Hostorio\Core;

use Hostorio\Database\AppDatabase;

/**
 * Records token usage and estimated spend for every outbound LLM call.
 *
 * Written in Phase 1 so that Phase 2's routing engine has somewhere to report
 * to from its very first call — cost data is what justifies the routing rules,
 * so it cannot be bolted on afterwards.
 *
 * Costs are *estimates* derived from the rate table in config/pricing.php.
 * Provider invoices remain the source of truth.
 */
final class CostTracker
{
    /**
     * @param string $provider  claude | deepseek | openai
     * @param array<string, mixed> $meta free-form context (conversation id, route reason, …)
     */
    public static function record(
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $durationMs = 0,
        bool $success = true,
        array $meta = []
    ): float {
        $cost = self::estimateCost($provider, $model, $inputTokens, $outputTokens);

        Logger::api('LLM call completed', [
            'provider'      => $provider,
            'model'         => $model,
            'input_tokens'  => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd'      => $cost,
            'duration_ms'   => $durationMs,
            'success'       => $success,
            'meta'          => $meta,
        ]);

        try {
            $db = AppDatabase::instance();

            $db->insert('api_costs', [
                'request_id'    => Logger::requestId(),
                'provider'      => $provider,
                'model'         => $model,
                'input_tokens'  => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd'      => $cost,
                'duration_ms'   => $durationMs,
                'success'       => $success ? 1 : 0,
                'meta'          => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_SLASHES),
                'created_at'    => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Never let accounting break a working chat response.
            Logger::error('Failed to persist cost record', ['error' => $e->getMessage()]);
        }

        return $cost;
    }

    /**
     * Estimate USD cost from the configured per-million-token rates.
     */
    public static function estimateCost(string $provider, string $model, int $inputTokens, int $outputTokens): float
    {
        $rates = Config::get('pricing.' . $provider . '.' . $model);

        if (!is_array($rates)) {
            // Unknown model: fall back to the provider default so spend is
            // still approximated rather than silently recorded as zero.
            $rates = Config::get('pricing.' . $provider . '._default');
        }

        if (!is_array($rates)) {
            Logger::warning('No pricing entry for model; cost recorded as 0', [
                'provider' => $provider,
                'model'    => $model,
            ]);

            return 0.0;
        }

        $inputRate  = (float) ($rates['input'] ?? 0.0);
        $outputRate = (float) ($rates['output'] ?? 0.0);

        $cost = (($inputTokens / 1_000_000) * $inputRate)
              + (($outputTokens / 1_000_000) * $outputRate);

        return round($cost, 6);
    }

    /**
     * Total estimated spend over the last N days, grouped by provider.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function spendByProvider(int $days = 30): array
    {
        try {
            $db = AppDatabase::instance();

            return $db->select(
                sprintf(
                    'SELECT provider,
                            COUNT(*)             AS calls,
                            SUM(input_tokens)    AS input_tokens,
                            SUM(output_tokens)   AS output_tokens,
                            SUM(cost_usd)        AS cost_usd
                     FROM `%s`
                     WHERE created_at >= :since
                     GROUP BY provider
                     ORDER BY cost_usd DESC',
                    $db->table('api_costs')
                ),
                ['since' => gmdate('Y-m-d H:i:s', time() - ($days * 86400))]
            );
        } catch (\Throwable $e) {
            Logger::error('Failed to read cost summary', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
