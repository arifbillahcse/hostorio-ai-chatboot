<?php

declare(strict_types=1);

namespace Hostorio\Database;

use Hostorio\Core\Config;

/**
 * READ-ONLY connector for the customer's WHMCS database.
 *
 * WHMCS tables are fixed-name (tblclients, tblhosting, …) so there is no
 * prefix to resolve. Phase 1 establishes connectivity only; the customer /
 * service / ticket lookups land in Phase 3 and Phase 4.
 *
 * Note: WHMCS stores several client fields encrypted with its own key. Those
 * columns are unreadable from a raw SQL connection by design — anything the
 * chatbot needs from them must go through the WHMCS API instead. That
 * distinction is handled in Phase 4.
 */
final class WhmcsDatabase extends Connection
{
    private static ?self $instance = null;

    protected bool $readOnly = true;

    protected string $label = 'WHMCS';

    public static function instance(): self
    {
        if (self::$instance === null) {
            /** @var array{host:string,port:int,name:string,user:string,pass:string,charset:string} $config */
            $config = Config::get('database.whmcs', []);

            self::$instance = new self($config);
        }

        return self::$instance;
    }

    /**
     * Sanity-check that this is a real WHMCS schema before Phase 3 tries to
     * read from it.
     */
    public function looksLikeWhmcs(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $row = $this->selectOne(
                "SELECT COUNT(*) AS c FROM information_schema.tables
                 WHERE table_schema = :schema AND table_name IN ('tblclients', 'tblhosting')",
                ['schema' => $this->config['name']]
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
