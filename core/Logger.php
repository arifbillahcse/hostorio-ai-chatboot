<?php

declare(strict_types=1);

namespace Hostorio\Core;

/**
 * File-based logger with daily rotation and automatic pruning.
 *
 * Chosen over a DB logger because logs must survive database outages — the
 * failure mode we most need to debug. One file per channel per day keeps
 * shared-hosting disk usage predictable.
 */
final class Logger
{
    public const DEBUG   = 'debug';
    public const INFO    = 'info';
    public const WARNING = 'warning';
    public const ERROR   = 'error';

    /** Severity ordering used to honour the configured minimum level. */
    private const LEVELS = [
        self::DEBUG   => 10,
        self::INFO    => 20,
        self::WARNING => 30,
        self::ERROR   => 40,
    ];

    /**
     * Keys whose values are masked before they ever reach disk.
     * Matched case-insensitively as a substring of the context key.
     */
    private const REDACT_KEYS = [
        'api_key', 'apikey', 'authorization', 'password', 'pass', 'secret',
        'token', 'x-api-key', 'db_pass', 'app_key',
    ];

    private static ?string $requestId = null;

    /**
     * Correlation id shared by every line written during one HTTP request.
     */
    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = bin2hex(random_bytes(8));
        }

        return self::$requestId;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function debug(string $message, array $context = [], string $channel = 'app'): void
    {
        self::write(self::DEBUG, $message, $context, $channel);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = [], string $channel = 'app'): void
    {
        self::write(self::INFO, $message, $context, $channel);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $message, array $context = [], string $channel = 'app'): void
    {
        self::write(self::WARNING, $message, $context, $channel);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = [], string $channel = 'app'): void
    {
        self::write(self::ERROR, $message, $context, $channel);
    }

    /**
     * Dedicated channel for outbound LLM calls — kept separate from app logs so
     * cost/latency analysis in the admin panel does not have to parse noise.
     *
     * @param array<string, mixed> $context
     */
    public static function api(string $message, array $context = []): void
    {
        self::write(self::INFO, $message, $context, 'api');
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function write(string $level, string $message, array $context, string $channel): void
    {
        if (self::LEVELS[$level] < self::minimumLevel()) {
            return;
        }

        $directory = self::directory();

        if (!self::ensureDirectory($directory)) {
            return;
        }

        $line = sprintf(
            "[%s] [%s] [%s] %s%s\n",
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            self::requestId(),
            $message,
            $context === [] ? '' : ' ' . self::encodeContext($context)
        );

        $file = $directory . '/' . self::sanitizeChannel($channel) . '-' . gmdate('Y-m-d') . '.log';

        // LOCK_EX keeps concurrent PHP workers from interleaving partial lines.
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        self::pruneOccasionally($directory);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function encodeContext(array $context): string
    {
        $encoded = json_encode(
            self::redact($context),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return $encoded === false ? '{"_log_error":"context not encodable"}' : $encoded;
    }

    /**
     * Recursively mask credential-shaped values so a stack trace or debug dump
     * can never write a live API key to disk.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::redact($value);
                continue;
            }

            $lowerKey = strtolower((string) $key);

            foreach (self::REDACT_KEYS as $needle) {
                if (str_contains($lowerKey, $needle)) {
                    $context[$key] = '***redacted***';
                    break;
                }
            }
        }

        return $context;
    }

    private static function minimumLevel(): int
    {
        $configured = strtolower((string) Config::get('logging.level', self::INFO));

        return self::LEVELS[$configured] ?? self::LEVELS[self::INFO];
    }

    private static function directory(): string
    {
        return rtrim((string) Config::get('logging.path', HOAI_ROOT . '/storage/logs'), '/');
    }

    private static function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return is_writable($directory);
        }

        return @mkdir($directory, 0750, true) && is_writable($directory);
    }

    private static function sanitizeChannel(string $channel): string
    {
        $clean = preg_replace('/[^a-z0-9_-]/i', '', $channel) ?? '';

        return $clean === '' ? 'app' : strtolower($clean);
    }

    /**
     * Delete log files older than the retention window. Runs probabilistically
     * (~1 in 100 writes) to avoid a directory scan on every single request.
     */
    private static function pruneOccasionally(string $directory): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }

        $retentionDays = (int) Config::get('logging.retention_days', 30);

        if ($retentionDays <= 0) {
            return;
        }

        $cutoff = time() - ($retentionDays * 86400);
        $files  = glob($directory . '/*.log');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
