<?php

declare(strict_types=1);

namespace Hostorio\Admin;

use Hostorio\Core\Config;
use Hostorio\Core\Logger;
use Hostorio\Database\AppDatabase;
use Throwable;

/**
 * Settings edited from the admin panel, stored in the database.
 *
 * Why the database rather than rewriting .env: making a config file writable by
 * the web server is a poor trade on shared hosting. Anything that can write
 * .env can rewrite the whole application's configuration, and the file usually
 * sits next to code the server also executes. A database row has none of that
 * blast radius.
 *
 * Some settings deliberately stay in .env and cannot be edited here:
 *
 *   - the application database credentials, because they are needed *to reach*
 *     this table — a chicken-and-egg problem, and a typo made through a web form
 *     would lock the panel out of its own storage;
 *   - APP_KEY, which signs identity tokens and hashes client keys. Changing it
 *     silently invalidates every issued token and orphans anonymous
 *     conversations, so it should be a deliberate deployment act.
 *
 * Values are applied as an overlay on top of the file configuration at
 * bootstrap, so an admin edit takes effect on the next request without a
 * deploy.
 */
final class SettingsStore
{
    /**
     * Dot-paths an administrator may change.
     *
     * An allowlist rather than "anything": without it, a form field named
     * `database.app.host` would repoint the application at another server.
     */
    private const EDITABLE = [
        // Providers
        'providers.default',
        'providers.claude.api_key',
        'providers.claude.model',
        'providers.deepseek.api_key',
        'providers.deepseek.model',
        'providers.openai.api_key',
        'providers.openai.model',

        // Embeddings
        'knowledge.embeddings.driver',
        'knowledge.embeddings.openai.api_key',
        'knowledge.embeddings.openai.model',

        // Routing
        'routing.rules',
        'routing.temperature',
        'routing.complex_length_threshold',

        // Chat behaviour
        'chat.company_name',
        'chat.history_turns',
        'chat.tools.enabled',
        'chat.tools.enable_actions',
        'chat.tools.max_rounds',

        // Widget branding
        'widget.title',
        'widget.subtitle',
        'widget.welcome',
        'widget.placeholder',
        'widget.launcherLabel',
        'widget.accent',
        'widget.accentText',
        'widget.position',
        'widget.suggestions',
        'widget.typewriter',
        'widget.branding',

        // Context budget
        'context.max_tokens',
    ];

    /**
     * Paths whose values are secret: never rendered back to a browser, only
     * ever replaced.
     */
    private const SECRET = [
        'providers.claude.api_key',
        'providers.deepseek.api_key',
        'providers.openai.api_key',
        'knowledge.embeddings.openai.api_key',
    ];

    private const PREFIX = 'cfg:';

    private readonly AppDatabase $db;

    public function __construct(?AppDatabase $db = null)
    {
        $this->db = $db ?? AppDatabase::instance();
    }

    public static function isEditable(string $path): bool
    {
        return in_array($path, self::EDITABLE, true);
    }

    public static function isSecret(string $path): bool
    {
        return in_array($path, self::SECRET, true);
    }

    /**
     * @return array<int, string>
     */
    public static function editablePaths(): array
    {
        return self::EDITABLE;
    }

    /**
     * Merge stored overrides into the live configuration.
     *
     * Called from bootstrap. Silent on failure by design: a database problem
     * must not stop the application booting on file configuration alone.
     */
    public static function apply(): void
    {
        try {
            $overrides = (new self())->all();
        } catch (Throwable) {
            return;
        }

        if ($overrides === []) {
            return;
        }

        $config = Config::all();

        foreach ($overrides as $path => $value) {
            if (!self::isEditable($path)) {
                // Ignore anything that was allowed once and is not any more.
                continue;
            }

            self::setPath($config, $path, $value);
        }

        Config::set($config);
    }

    /**
     * Stored overrides, decoded.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $rows = $this->db->select(
            sprintf(
                'SELECT setting_key, setting_value FROM `%s` WHERE setting_key LIKE :prefix',
                $this->db->table('settings')
            ),
            ['prefix' => self::PREFIX . '%']
        );

        $values = [];

        foreach ($rows as $row) {
            $path = substr((string) $row['setting_key'], strlen(self::PREFIX));

            $decoded = json_decode((string) $row['setting_value'], true);

            // A row that will not decode is corrupt; skipping it is better than
            // poisoning the configuration with a raw string.
            if (json_last_error() !== JSON_ERROR_NONE) {
                Logger::warning('Skipping unreadable setting', ['key' => $path]);
                continue;
            }

            $values[$path] = $decoded;
        }

        return $values;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($path, $all) ? $all[$path] : $default;
    }

    /**
     * Store one override.
     *
     * @throws \InvalidArgumentException when the path is not editable
     */
    public function set(string $path, mixed $value): void
    {
        if (!self::isEditable($path)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an editable setting.', $path));
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new \InvalidArgumentException('That value could not be stored.');
        }

        $this->db->execute(
            sprintf(
                'INSERT INTO `%s` (setting_key, setting_value, updated_at)
                 VALUES (:k, :v, :now)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)',
                $this->db->table('settings')
            ),
            ['k' => self::PREFIX . $path, 'v' => $encoded, 'now' => gmdate('Y-m-d H:i:s')]
        );

        Logger::info('Setting updated from admin panel', [
            'key' => $path,
            // Never log the value of a secret.
            'value' => self::isSecret($path) ? '***redacted***' : $value,
        ]);
    }

    /**
     * Remove an override so the .env / file value applies again.
     */
    public function forget(string $path): void
    {
        $this->db->execute(
            sprintf('DELETE FROM `%s` WHERE setting_key = :k', $this->db->table('settings')),
            ['k' => self::PREFIX . $path]
        );

        Logger::info('Setting reverted to file configuration', ['key' => $path]);
    }

    /**
     * Write a value into a nested array by dot path.
     *
     * @param array<string, mixed> $target
     */
    private static function setPath(array &$target, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $cursor   = &$target;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;
                break;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }
}
