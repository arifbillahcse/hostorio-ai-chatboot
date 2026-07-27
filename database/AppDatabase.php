<?php

declare(strict_types=1);

namespace Hostorio\Database;

use Hostorio\Core\Config;
use RuntimeException;

/**
 * The chatbot's own database — the only connection with write access.
 *
 * Holds conversations, the knowledge index, rate-limit counters and cost
 * records. Table names are prefixed so the app can safely share a database
 * with WordPress or WHMCS if the customer only has one MySQL database.
 */
final class AppDatabase extends Connection
{
    private static ?self $instance = null;

    protected bool $readOnly = false;

    protected string $label = 'application';

    private string $prefix;

    /**
     * @param array{host?:string,port?:int,name?:string,user?:string,pass?:string,charset?:string,prefix?:string} $config
     */
    public function __construct(array $config)
    {
        parent::__construct($config);

        // Set here rather than by the factory below: leaving a typed property
        // uninitialised meant that constructing this class directly — which the
        // public constructor invites — threw on the first table() call instead
        // of simply working.
        $this->prefix = (string) ($config['prefix'] ?? 'hoai_');
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            /** @var array{host:string,port:int,name:string,user:string,pass:string,charset:string,prefix:string} $config */
            $config = Config::get('database.app', []);

            self::$instance = new self($config);
        }

        return self::$instance;
    }

    /**
     * Resolve a logical table name to its prefixed physical name.
     */
    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Insert a row and return the new primary key.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);

        /*
         * Column names are the one part of a prepared statement that cannot be
         * parameterised — they are concatenated into the SQL. Every caller
         * today passes literal keys, but this method is a public API and a
         * future caller building `$data` from request input would turn that
         * into injection. Rejecting anything that is not a plain identifier
         * costs nothing and removes the whole class of mistake.
         */
        foreach ($columns as $column) {
            if (!is_string($column) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column) !== 1) {
                throw new RuntimeException(sprintf(
                    'Invalid column name "%s" in insert into %s.',
                    is_scalar($column) ? (string) $column : get_debug_type($column),
                    $table
                ));
            }
        }

        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->table($table),
            '`' . implode('`, `', $columns) . '`',
            implode(', ', $placeholders)
        );

        $this->execute($sql, $data);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * True when the schema has been installed.
     */
    public function isInstalled(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $this->select(sprintf('SELECT 1 FROM `%s` LIMIT 1', $this->table('settings')));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Reset the singleton. Installer and tests only.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
