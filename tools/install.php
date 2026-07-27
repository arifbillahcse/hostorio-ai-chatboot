<?php

declare(strict_types=1);

/**
 * Schema installer.
 *
 * Usage (SSH):        php tools/install.php
 * Usage (no SSH):     run database/schema/install.sql in phpMyAdmin instead,
 *                     adjusting the table prefix to match DB_PREFIX.
 *
 * Applies the prefix from .env to the shipped SQL, so a customer who changes
 * DB_PREFIX does not have to hand-edit the schema file.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This installer must be run from the command line.\n");
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Hostorio\Core\Config;
use Hostorio\Database\AppDatabase;

$db = AppDatabase::instance();

if (!$db->isConfigured()) {
    fwrite(STDERR, "Error: the application database is not configured.\n");
    fwrite(STDERR, "Copy .env.example to .env and fill in DB_NAME, DB_USER and DB_PASS.\n");
    exit(1);
}

/*
 * Schema files are applied in order. Every statement is CREATE TABLE IF NOT
 * EXISTS or an idempotent upsert, so re-running the installer is safe and is
 * how an existing install picks up a later phase's tables.
 */
$schemaFiles = ['install.sql', 'phase3.sql'];

$prefix = $db->prefix();

echo "Installing schema into `" . Config::get('database.app.name') . "` with prefix `{$prefix}`…\n";

$applied = 0;

foreach ($schemaFiles as $name) {
    $schemaFile = HOAI_ROOT . '/database/schema/' . $name;

    if (!is_readable($schemaFile)) {
        fwrite(STDERR, "Error: schema file not found at {$schemaFile}\n");
        exit(1);
    }

    $sql = (string) file_get_contents($schemaFile);

    // Re-prefix the shipped table names to whatever DB_PREFIX is set to.
    if ($prefix !== 'hoai_') {
        $sql = str_replace('`hoai_', '`' . $prefix, $sql);
    }

    // Split on semicolons at end of line — sufficient for these schemas, which
    // contain no stored routines or semicolons inside string literals.
    $statements = array_filter(
        array_map('trim', preg_split('/;\s*[\r\n]+/', $sql) ?: []),
        static fn (string $s): bool => $s !== '' && !str_starts_with($s, '--')
    );

    foreach ($statements as $statement) {
        try {
            $db->execute($statement);
            $applied++;
        } catch (Throwable $e) {
            fwrite(STDERR, "Failed in {$name}: " . $e->getMessage() . "\n");
            exit(1);
        }
    }

    echo "  applied {$name}\n";
}

echo "Applied {$applied} statement(s).\n";
echo $db->isInstalled()
    ? "Schema installed successfully.\n"
    : "Warning: schema applied but verification failed.\n";
