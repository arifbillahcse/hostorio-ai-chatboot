<?php

declare(strict_types=1);

/**
 * Pre-flight security and configuration audit.
 *
 * Run this on the server before handing an install to real customers, and again
 * after any configuration change:
 *
 *   php tools/security-check.php
 *
 * Exits non-zero if anything CRITICAL is found, so it can gate a deployment.
 *
 * This checks the things that are wrong *about a particular install* — file
 * permissions, weak keys, debug left on. The code-level protections (prepared
 * statements, output escaping, CSRF) are covered by the test suite instead,
 * because they cannot regress per-install.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Hostorio\Core\Config;
use Hostorio\Core\CostGuard;
use Hostorio\Database\AppDatabase;
use Hostorio\Database\WhmcsDatabase;
use Hostorio\Database\WordPressDatabase;

$critical = 0;
$warnings = 0;
$passes   = 0;

function result(string $level, string $label, string $detail = ''): void
{
    global $critical, $warnings, $passes;

    $marker = match ($level) {
        'critical' => '[CRITICAL]',
        'warn'     => '[ warn   ]',
        default    => '[ ok     ]',
    };

    match ($level) {
        'critical' => $critical++,
        'warn'     => $warnings++,
        default    => $passes++,
    };

    printf("  %-11s %s\n", $marker, $label);

    if ($detail !== '') {
        printf("              %s\n", $detail);
    }
}

function section(string $name): void
{
    echo "\n{$name}\n" . str_repeat('-', 68) . "\n";
}

echo "\nHostorio AI Chatbot — security check\n" . str_repeat('=', 68) . "\n";

// ── Environment ──────────────────────────────────────────────────────────────
section('Environment');

$env = (string) Config::get('app.env', 'production');
$debug = (bool) Config::get('app.debug', false);

if ($debug && $env === 'production') {
    result('critical', 'APP_DEBUG is on in production',
        'Stack traces and internal error messages are returned to callers. Set APP_DEBUG=false.');
} elseif ($debug) {
    result('warn', 'APP_DEBUG is on', 'Fine for staging; never leave it on for real customers.');
} else {
    result('ok', 'APP_DEBUG is off');
}

$key = (string) Config::get('app.key', '');

if ($key === '') {
    result('critical', 'APP_KEY is not set',
        'Identity tokens cannot be verified, so no customer can be recognised. '
        . 'Generate one: php -r "echo bin2hex(random_bytes(32));"');
} elseif (strlen($key) < 32) {
    result('critical', 'APP_KEY is too short',
        'It signs identity tokens. Use at least 32 characters of random hex.');
} elseif (preg_match('/^(.)\1+$/', $key) === 1 || str_contains($key, '0123456789abcdef')) {
    result('critical', 'APP_KEY looks like a placeholder',
        'Replace it with real random bytes before going live.');
} else {
    result('ok', 'APP_KEY is set and long enough');
}

result(
    ini_get('display_errors') ? 'critical' : 'ok',
    ini_get('display_errors') ? 'display_errors is on at the PHP level' : 'display_errors is off',
    ini_get('display_errors') ? 'PHP notices will corrupt JSON responses and leak paths.' : ''
);

// ── Secrets on disk ──────────────────────────────────────────────────────────
section('Secrets on disk');

$envFiles = array_filter([
    dirname(HOAI_ROOT) . '/.hoai.env',
    HOAI_ROOT . '/.env',
], 'is_file');

if ($envFiles === []) {
    result('warn', 'No .env file found', 'The application is running entirely on defaults.');
}

foreach ($envFiles as $file) {
    $perms = fileperms($file) & 0777;

    if (($perms & 0o044) !== 0) {
        result('critical', sprintf('%s is readable by other users (%04o)', basename($file), $perms),
            'On shared hosting that can mean other accounts. Run: chmod 600 ' . $file);
    } else {
        result('ok', sprintf('%s permissions are %04o', basename($file), $perms));
    }

    // A .env inside the document root relies entirely on .htaccess being honoured.
    if (str_starts_with($file, HOAI_ROOT . '/')) {
        result('warn', '.env sits inside the application directory',
            'Safer: move it above the web root as ../.hoai.env, which cannot be served over HTTP '
            . 'even if .htaccess is ignored.');
    } else {
        result('ok', '.env is above the application directory');
    }
}

// ── Web exposure ─────────────────────────────────────────────────────────────
section('Web exposure');

$missingGuards = [];

foreach (['.htaccess', 'admin/.htaccess', 'api/.htaccess', 'config/.htaccess', 'core/.htaccess',
          'database/.htaccess', 'storage/.htaccess', 'llm/.htaccess', 'chat/.htaccess',
          'context/.htaccess', 'knowledge/.htaccess', 'tools/.htaccess',
          'integrations/.htaccess'] as $guard) {
    if (!is_file(HOAI_ROOT . '/' . $guard)) {
        $missingGuards[] = $guard;
    }
}

if ($missingGuards !== []) {
    result('critical', sprintf('Missing %d directory access guard(s)', count($missingGuards)),
        implode(', ', $missingGuards) . ' — application source may be downloadable over HTTP.');
} else {
    result('ok', 'Directory access guards are present');
}

$logDir = (string) Config::get('logging.path', '');

if ($logDir !== '' && str_starts_with($logDir, HOAI_ROOT . '/storage')) {
    result('ok', 'Logs are under storage/, which denies web access');
} else {
    result('warn', 'Logs are outside storage/', 'Confirm they are not reachable over HTTP: ' . $logDir);
}

// ── Admin panel ──────────────────────────────────────────────────────────────
section('Admin panel');

$hash = (string) Config::get('security.admin_password_hash', '');

if ($hash === '') {
    result('warn', 'No admin password is set',
        'The panel is unreachable until ADMIN_PASSWORD_HASH is configured.');
} elseif (!str_starts_with($hash, '$2y$') && !str_starts_with($hash, '$argon2')) {
    result('critical', 'ADMIN_PASSWORD_HASH is not a password hash',
        'It must be the output of password_hash(), not the password itself.');
} else {
    result('ok', 'Admin password hash is configured');
}

// ── CORS ─────────────────────────────────────────────────────────────────────
section('Cross-origin access');

/** @var array<int, string> $origins */
$origins = (array) Config::get('security.allowed_origins', []);

if (in_array('*', $origins, true)) {
    result('critical', 'CORS allows any origin',
        'Any site could embed the widget and spend your API budget. List real origins instead.');
} elseif ($origins === []) {
    result('warn', 'No allowed origins configured',
        'Browsers on other hosts cannot use the widget. Set APP_URL, or list origins explicitly.');
} else {
    result('ok', sprintf('CORS restricted to %d origin(s)', count($origins)));
}

// ── Rate limiting ────────────────────────────────────────────────────────────
section('Rate limiting');

if (!Config::get('rate_limit.enabled', true)) {
    result('critical', 'Rate limiting is disabled',
        'One scraper can spend your entire API budget. Set RATE_LIMIT_ENABLED=true.');
} else {
    $max    = (int) Config::get('rate_limit.max_requests', 20);
    $window = (int) Config::get('rate_limit.window', 60);

    if ($max > 100) {
        result('warn', sprintf('Rate limit is loose (%d per %ds)', $max, $window));
    } else {
        result('ok', sprintf('Rate limiting is on (%d per %ds)', $max, $window));
    }
}

/** @var array<int, string> $proxies */
$proxies = (array) Config::get('security.trusted_proxies', []);

if ($proxies === []) {
    result('warn', 'No trusted proxies configured',
        'If this site sits behind Cloudflare or another proxy, every visitor appears to come from '
        . 'the proxy IP and therefore shares ONE rate-limit bucket. Add the proxy IPs to '
        . 'security.trusted_proxies so the real client IP is used.');
} else {
    result('ok', sprintf('%d trusted proxy address(es) configured', count($proxies)));
}

// ── Database access ──────────────────────────────────────────────────────────
section('Database access');

$app = AppDatabase::instance();

if (!$app->isConfigured()) {
    result('critical', 'Application database is not configured');
} elseif (!$app->ping()) {
    result('critical', 'Application database is unreachable');
} else {
    result('ok', 'Application database reachable');

    if (!$app->isInstalled()) {
        result('critical', 'Schema is not installed', 'Run: php tools/install.php');
    } else {
        result('ok', 'Schema is installed');
    }
}

foreach (['WordPress' => WordPressDatabase::instance(), 'WHMCS' => WhmcsDatabase::instance()] as $name => $connection) {
    if (!$connection->isConfigured()) {
        result('ok', "{$name} connector disabled");
        continue;
    }

    // The in-code guard should reject a write before it reaches MySQL.
    $blocked = false;

    try {
        $connection->execute('UPDATE information_schema.tables SET table_name = 1');
    } catch (Throwable $e) {
        $blocked = str_contains($e->getMessage(), 'read-only');
    }

    result(
        $blocked ? 'ok' : 'critical',
        $blocked
            ? "{$name} connection rejects writes in code"
            : "{$name} connection did NOT reject a write",
        $blocked ? '' : 'The read-only guard is not working. Do not go live.'
    );

    if ($connection->ping()) {
        // Independently of the code guard, the MySQL grant should be SELECT-only.
        try {
            $grants = $connection->select('SHOW GRANTS');
            $writable = false;

            foreach ($grants as $row) {
                $grant = (string) reset($row);

                if (preg_match('/\b(ALL PRIVILEGES|INSERT|UPDATE|DELETE|DROP)\b/i', $grant) === 1) {
                    $writable = true;
                    break;
                }
            }

            result(
                $writable ? 'warn' : 'ok',
                $writable
                    ? "{$name} MySQL user has write privileges"
                    : "{$name} MySQL user is SELECT-only",
                $writable
                    ? 'The code guard blocks writes, but a SELECT-only grant is the real safety net. '
                    . 'See the README for the GRANT statement.'
                    : ''
            );
        } catch (Throwable) {
            result('warn', "Could not read {$name} grants", 'Verify manually that it is SELECT-only.');
        }
    }
}

// ── Providers and spend ──────────────────────────────────────────────────────
section('Providers and spend');

$enabled = array_filter(['claude', 'deepseek', 'openai'], static fn (string $p): bool =>
    (bool) Config::get("providers.{$p}.enabled", false));

if ($enabled === []) {
    result('critical', 'No LLM provider is configured', 'The chatbot cannot answer anything.');
} else {
    result('ok', sprintf('Providers configured: %s', implode(', ', $enabled)));
}

if (!in_array('claude', $enabled, true) && Config::get('chat.tools.enabled', true)) {
    result('warn', 'Tools are enabled but Claude is not configured',
        'Tool calling is routed to Claude first; without it, actions fall back to OpenAI.');
}

if (Config::get('chat.tools.enable_actions', false)) {
    result('warn', 'Destructive actions are enabled',
        'Confirm an ActionExecutorInterface is registered. Without one the tools refuse safely, '
        . 'but the model will be told it can attempt them.');
} else {
    result('ok', 'Destructive actions are disabled');
}

$daily  = (float) Config::get('costs.daily_budget', 0);
$hourly = (float) Config::get('costs.hourly_budget', 0);

if ($daily <= 0 && $hourly <= 0) {
    result('warn', 'No spend budget configured',
        'A scraper or a runaway loop can spend without limit and nothing will flag it. '
        . 'Set COST_DAILY_BUDGET and COST_HOURLY_BUDGET.');
} else {
    result('ok', sprintf('Spend budgets set (hourly $%.2f, daily $%.2f)', $hourly, $daily));

    try {
        $status = CostGuard::check();

        if ($status['status'] !== CostGuard::OK) {
            result('warn', 'Spend is currently ' . $status['status'], implode(' ', $status['reasons']));
        } else {
            result('ok', sprintf(
                'Current spend within budget (hour $%.4f, day $%.4f)',
                $status['hourly']['spend'],
                $status['daily']['spend']
            ));
        }
    } catch (Throwable $e) {
        result('warn', 'Could not evaluate current spend', $e->getMessage());
    }
}

// ── PHP runtime ──────────────────────────────────────────────────────────────
section('PHP runtime');

$limit = (int) ini_get('max_execution_time');
$httpTimeout = (int) Config::get('http.timeout', 60);

if ($limit > 0 && $httpTimeout >= $limit) {
    result('warn', sprintf('HTTP timeout (%ds) is not below max_execution_time (%ds)', $httpTimeout, $limit),
        'PHP will be killed mid-request, so the customer sees a blank page instead of an error. '
        . 'The client clamps this at runtime, but lowering HTTP_TIMEOUT is clearer.');
} else {
    result('ok', sprintf('HTTP timeout %ds vs execution limit %s',
        $httpTimeout, $limit > 0 ? $limit . 's' : 'unlimited'));
}

foreach (['curl', 'pdo_mysql', 'mbstring', 'json'] as $extension) {
    if (!extension_loaded($extension)) {
        result('critical', "PHP extension {$extension} is missing");
    }
}

result('ok', sprintf('PHP %s', PHP_VERSION));

// ── Verdict ──────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 68) . "\n";
printf("%d passed, %d warning(s), %d critical\n\n", $passes, $warnings, $critical);

if ($critical > 0) {
    echo "NOT safe to hand to customers — resolve the CRITICAL items above.\n\n";
    exit(1);
}

if ($warnings > 0) {
    echo "No blockers. Review the warnings before going live.\n\n";
    exit(0);
}

echo "All checks passed.\n\n";
exit(0);
