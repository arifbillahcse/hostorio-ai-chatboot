<?php

declare(strict_types=1);

/**
 * CLI health check — the fastest way to verify a Phase 1 install.
 *
 * Usage: php tools/healthcheck.php
 *
 * Prints the same checks as GET /api/health/diagnostics, but readable and
 * without needing the site to be reachable over HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line, or use GET /api/health/diagnostics.\n");
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Hostorio\Core\Config;
use Hostorio\Database\AppDatabase;
use Hostorio\Database\WhmcsDatabase;
use Hostorio\Database\WordPressDatabase;

/** Render a boolean as a readable status marker. */
function mark(bool $ok, string $okLabel = 'OK', string $failLabel = 'FAIL'): string
{
    return ($ok ? "[ + ] " : "[ - ] ") . ($ok ? $okLabel : $failLabel);
}

function line(string $label, string $value): void
{
    printf("  %-28s %s\n", $label, $value);
}

echo "\nHostorio AI Chatbot — health check\n";
echo str_repeat('=', 60) . "\n\n";

// ── Environment ──────────────────────────────────────────────────────────────
echo "Environment\n";
line('PHP version', PHP_VERSION . '  ' . mark(PHP_VERSION_ID >= 80100, 'supported', 'needs 8.1+'));
line('App environment', (string) Config::get('app.env'));
line('Debug mode', Config::get('app.debug') ? 'ON  (disable in production)' : 'off');
line('Timezone', date_default_timezone_get());

$appKey = (string) Config::get('app.key', '');
line('APP_KEY', mark(strlen($appKey) >= 32, 'set', 'missing or too short'));
line('cURL extension', mark(function_exists('curl_init')));
line('PDO MySQL driver', mark(in_array('mysql', PDO::getAvailableDrivers(), true)));
line('mbstring extension', mark(extension_loaded('mbstring')));
echo "\n";

// ── Storage ──────────────────────────────────────────────────────────────────
echo "Storage\n";

foreach (['logs', 'cache'] as $dir) {
    $path = HOAI_ROOT . '/storage/' . $dir;

    if (!is_dir($path)) {
        @mkdir($path, 0750, true);
    }

    line('storage/' . $dir, mark(is_dir($path) && is_writable($path), 'writable', 'not writable'));
}
echo "\n";

// ── Databases ────────────────────────────────────────────────────────────────
echo "Databases\n";

$app = AppDatabase::instance();
line('Application (read/write)', $app->isConfigured()
    ? mark($app->ping(), 'connected', 'unreachable')
    : '[ - ] not configured  (required)');

if ($app->isConfigured() && $app->ping()) {
    line('  schema installed', mark($app->isInstalled(), 'yes', 'no — run php tools/install.php'));
    line('  table prefix', $app->prefix());
}

$wp = WordPressDatabase::instance();
line('WordPress (read-only)', $wp->isConfigured()
    ? mark($wp->ping(), 'connected', 'unreachable')
    : '[ ~ ] not configured  (optional)');

if ($wp->isConfigured() && $wp->ping()) {
    line('  schema recognised', mark($wp->looksLikeWordPress(), 'yes', 'no — check WP_DB_NAME/prefix'));
    line('  table prefix', $wp->tablePrefix());
}

$whmcs = WhmcsDatabase::instance();
line('WHMCS (read-only)', $whmcs->isConfigured()
    ? mark($whmcs->ping(), 'connected', 'unreachable')
    : '[ ~ ] not configured  (optional)');

if ($whmcs->isConfigured() && $whmcs->ping()) {
    line('  schema recognised', mark($whmcs->looksLikeWhmcs(), 'yes', 'no — check WHMCS_DB_NAME'));
}
echo "\n";

// ── Read-only enforcement ────────────────────────────────────────────────────
echo "Read-only enforcement\n";

foreach (['WordPress' => $wp, 'WHMCS' => $whmcs] as $name => $connection) {
    $blocked = false;

    try {
        $connection->execute('UPDATE some_table SET x = 1');
    } catch (Throwable) {
        $blocked = true;
    }

    line($name . ' rejects writes', mark($blocked, 'blocked', 'NOT BLOCKED'));
}
echo "\n";

// ── Providers ────────────────────────────────────────────────────────────────
echo "LLM providers\n";

$anyProvider = false;

foreach (['claude', 'deepseek', 'openai'] as $provider) {
    $enabled = (bool) Config::get("providers.{$provider}.enabled", false);
    $anyProvider = $anyProvider || $enabled;

    line(
        ucfirst($provider),
        $enabled
            ? '[ + ] key set    model: ' . Config::get("providers.{$provider}.model")
            : '[ ~ ] no API key'
    );
}

line('Default provider', (string) Config::get('providers.default'));
line('At least one enabled', mark($anyProvider, 'yes', 'no — add a key before Phase 2'));
echo "\n";

// ── Verdict ──────────────────────────────────────────────────────────────────
$ready = $app->isConfigured()
    && $app->ping()
    && is_writable(HOAI_ROOT . '/storage/logs')
    && function_exists('curl_init')
    && in_array('mysql', PDO::getAvailableDrivers(), true);

echo str_repeat('=', 60) . "\n";
echo $ready
    ? "Foundation is ready.\n\n"
    : "Foundation is NOT ready — resolve the items marked [ - ] above.\n\n";

exit($ready ? 0 : 1);
