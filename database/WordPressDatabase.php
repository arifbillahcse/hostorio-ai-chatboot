<?php

declare(strict_types=1);

namespace Hostorio\Database;

use Hostorio\Core\Config;

/**
 * READ-ONLY connector for the customer's WordPress database.
 *
 * Phase 1 only establishes the connection and exposes table-name resolution.
 * The content extraction queries land in Phase 3 (knowledge ingestion).
 */
final class WordPressDatabase extends Connection
{
    private static ?self $instance = null;

    protected bool $readOnly = true;

    protected string $label = 'WordPress';

    private string $tablePrefix = 'wp_';

    public static function instance(): self
    {
        if (self::$instance === null) {
            /** @var array{host:string,port:int,name:string,user:string,pass:string,charset:string,table_prefix:string} $config */
            $config = Config::get('database.wordpress', []);

            self::$instance = new self($config);
            self::$instance->tablePrefix = (string) ($config['table_prefix'] ?? 'wp_');
        }

        return self::$instance;
    }

    /**
     * Resolve a WordPress table name, e.g. table('posts') => 'wp_posts'.
     */
    public function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }

    public function tablePrefix(): string
    {
        return $this->tablePrefix;
    }

    /**
     * Verify the connection points at something that actually looks like a
     * WordPress install, so a mis-typed database name fails loudly at setup
     * rather than silently returning an empty knowledge base later.
     */
    public function looksLikeWordPress(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $row = $this->selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.tables
                 WHERE table_schema = :schema AND table_name IN (:posts, :options)',
                [
                    'schema'  => $this->config['name'],
                    'posts'   => $this->table('posts'),
                    'options' => $this->table('options'),
                ]
            );

            return (int) ($row['c'] ?? 0) === 2;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
