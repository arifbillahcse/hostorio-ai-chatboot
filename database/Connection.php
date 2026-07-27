<?php

declare(strict_types=1);

namespace Hostorio\Database;

use Hostorio\Core\Logger;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Base PDO wrapper. Connections are lazy — constructing a connector does not
 * open a socket, so the app boots fine with WordPress/WHMCS unconfigured.
 *
 * Subclasses set $readOnly to reject any statement that is not a SELECT. That
 * guard is defence-in-depth; the deployment guide also asks for a SELECT-only
 * MySQL user on the WordPress and WHMCS databases.
 */
abstract class Connection
{
    protected ?PDO $pdo = null;

    protected bool $readOnly = false;

    /** Human-readable name used in logs and health checks. */
    protected string $label = 'database';

    /**
     * @param array{host:string,port:int,name:string,user:string,pass:string,charset:string} $config
     */
    public function __construct(protected array $config)
    {
    }

    /**
     * True when enough configuration is present to attempt a connection.
     * A blank database name is the documented way to disable a connector.
     */
    public function isConfigured(): bool
    {
        return ($this->config['name'] ?? '') !== '' && ($this->config['user'] ?? '') !== '';
    }

    /**
     * Open the connection on first use.
     *
     * @throws RuntimeException when the connector is disabled or unreachable
     */
    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        if (!$this->isConfigured()) {
            throw new RuntimeException(
                sprintf('The %s connection is not configured.', $this->label)
            );
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['name'],
            $this->config['charset']
        );

        try {
            $this->pdo = new PDO($dsn, $this->config['user'], $this->config['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Real prepared statements — the server never sees interpolated values.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // The message can contain the DSN (and therefore the username), so
            // log it at error level but never surface it to the caller.
            Logger::error('Database connection failed', [
                'connection' => $this->label,
                'host'       => $this->config['host'],
                'database'   => $this->config['name'],
                'error'      => $e->getMessage(),
            ]);

            throw new RuntimeException(
                sprintf('Could not connect to the %s database.', $this->label),
                0,
                $e
            );
        }

        Logger::debug('Database connection opened', ['connection' => $this->label]);

        return $this->pdo;
    }

    /**
     * Run a parameterised query and return all rows.
     *
     * @param array<string|int, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $this->assertStatementAllowed($sql);

        $started    = microtime(true);
        $statement  = $this->pdo()->prepare($sql);
        $statement->execute($bindings);
        $rows       = $statement->fetchAll();
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if ($durationMs > 1000) {
            Logger::warning('Slow query', [
                'connection'  => $this->label,
                'duration_ms' => $durationMs,
                'sql'         => $this->summarize($sql),
            ]);
        }

        return $rows;
    }

    /**
     * Run a parameterised query and return the first row, or null.
     *
     * @param array<string|int, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $rows = $this->select($sql, $bindings);

        return $rows[0] ?? null;
    }

    /**
     * Run a write statement. Blocked entirely on read-only connections.
     *
     * @param array<string|int, mixed> $bindings
     * @return int number of affected rows
     */
    public function execute(string $sql, array $bindings = []): int
    {
        $this->assertStatementAllowed($sql);

        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->rowCount();
    }

    /**
     * Reject writes on read-only connections before they reach MySQL.
     */
    protected function assertStatementAllowed(string $sql): void
    {
        if (!$this->readOnly) {
            return;
        }

        // Look at the first keyword, ignoring leading comments and whitespace.
        $normalized = ltrim($sql);
        $normalized = preg_replace('/^(\/\*.*?\*\/|--[^\n]*\n|#[^\n]*\n|\s)+/s', '', $normalized) ?? $normalized;

        if (preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $normalized) !== 1) {
            Logger::error('Blocked write attempt on read-only connection', [
                'connection' => $this->label,
                'sql'        => $this->summarize($sql),
            ]);

            throw new RuntimeException(
                sprintf('The %s connection is read-only; only SELECT statements are permitted.', $this->label)
            );
        }
    }

    /**
     * Trim SQL for log output so a large IN() clause does not fill the disk.
     */
    protected function summarize(string $sql): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

        return strlen($collapsed) > 300 ? substr($collapsed, 0, 300) . '…' : $collapsed;
    }

    /**
     * Cheap liveness probe used by the health check.
     */
    public function ping(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }
}
