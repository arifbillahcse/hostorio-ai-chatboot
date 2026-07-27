<?php

declare(strict_types=1);

namespace Hostorio\Core;

use Hostorio\Database\AppDatabase;
use Throwable;

/**
 * Version reporting and comparison.
 *
 * Two versions matter and they move independently:
 *
 *  - the **code** version, from the VERSION file, which changes when files are
 *    replaced;
 *  - the **schema** version, stored in the settings table, which changes when
 *    migrations run.
 *
 * Keeping them separate is what makes an interrupted update recoverable: new
 * code with an old schema is a state the updater can detect and finish, whereas
 * a single combined number would only tell you something is wrong.
 */
final class Version
{
    public static function code(): string
    {
        $file = HOAI_ROOT . '/VERSION';

        if (!is_readable($file)) {
            return '0.0.0';
        }

        return trim((string) file_get_contents($file)) ?: '0.0.0';
    }

    /**
     * Schema version recorded in the database, or 0 when not installed.
     */
    public static function schema(): int
    {
        try {
            $db  = AppDatabase::instance();
            $row = $db->selectOne(
                sprintf('SELECT setting_value FROM `%s` WHERE setting_key = :k', $db->table('settings')),
                ['k' => 'schema_version']
            );

            return (int) ($row['setting_value'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    public static function setSchema(int $version): void
    {
        $db = AppDatabase::instance();

        $db->execute(
            sprintf(
                'INSERT INTO `%s` (setting_key, setting_value, updated_at)
                 VALUES (:k, :v, :now)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)',
                $db->table('settings')
            ),
            ['k' => 'schema_version', 'v' => (string) $version, 'now' => gmdate('Y-m-d H:i:s')]
        );
    }

    /**
     * Compare two dotted versions. Returns -1, 0 or 1.
     *
     * Written by hand rather than using version_compare() so the behaviour is
     * predictable for the simple numeric versions this project uses; PHP's
     * function has surprising opinions about suffixes.
     */
    public static function compare(string $a, string $b): int
    {
        $left  = array_map('intval', explode('.', trim($a)));
        $right = array_map('intval', explode('.', trim($b)));

        $length = max(count($left), count($right));

        for ($i = 0; $i < $length; $i++) {
            $l = $left[$i] ?? 0;
            $r = $right[$i] ?? 0;

            if ($l !== $r) {
                return $l <=> $r;
            }
        }

        return 0;
    }

    public static function isNewer(string $candidate, string $current): bool
    {
        return self::compare($candidate, $current) > 0;
    }
}
