<?php

declare(strict_types=1);

namespace Hostorio\Core;

/**
 * Minimal .env file loader.
 *
 * Reads KEY=VALUE pairs into an internal store. Deliberately does NOT populate
 * $_ENV / getenv(), so credentials cannot leak through phpinfo(), error dumps,
 * or child processes spawned by other scripts on a shared cPanel account.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $vars = [];

    private static bool $loaded = false;

    /**
     * Load an .env file. Missing files are tolerated so the health check can
     * report a clean "not configured yet" state instead of fataling.
     */
    public static function load(string $path): void
    {
        self::$loaded = true;

        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key = trim($key);
            if ($key === '') {
                continue;
            }

            self::$vars[$key] = self::normalizeValue($value);
        }
    }

    /**
     * Strip inline comments and surrounding quotes from a raw value.
     */
    private static function normalizeValue(string $value): string
    {
        $value = trim($value);

        // Quoted values keep everything inside the quotes verbatim.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        // Unquoted values: everything after an unescaped ` #` is a comment.
        if (preg_match('/^(.*?)\s+#.*$/', $value, $m) === 1) {
            $value = $m[1];
        }

        return trim($value);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::$vars[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return ($value === null || !is_numeric($value)) ? $default : (int) $value;
    }

    public static function has(string $key): bool
    {
        return isset(self::$vars[$key]) && self::$vars[$key] !== '';
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /**
     * Reset the store. Used by tests and the installer only.
     */
    public static function reset(): void
    {
        self::$vars  = [];
        self::$loaded = false;
    }
}
