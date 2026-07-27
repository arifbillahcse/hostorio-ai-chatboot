<?php

declare(strict_types=1);

namespace Hostorio\Core;

/**
 * Read-only configuration registry with dot-notation access.
 *
 * Values are assembled once at bootstrap from config/*.php (which in turn read
 * from Env). Nothing writes to this at runtime.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    /**
     * @param array<string, mixed> $items
     */
    public static function set(array $items): void
    {
        self::$items = $items;
    }

    /**
     * Fetch a value by dot path, e.g. Config::get('database.app.host').
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $current  = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    public static function has(string $key): bool
    {
        return self::get($key, '__missing__') !== '__missing__';
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::$items;
    }
}
