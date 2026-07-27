<?php

declare(strict_types=1);

/**
 * Browser installer.
 *
 * The audience is a hosting customer who has uploaded a zip through cPanel's
 * File Manager and has no SSH access. It walks them through requirements,
 * database credentials, an API key and an admin password, writes the .env file
 * and creates the schema.
 *
 * Deliberately standalone: it does NOT include bootstrap.php, because bootstrap
 * requires configuration that does not exist yet. Everything it needs is
 * inlined.
 *
 * Security
 * --------
 * An installer is the most dangerous file in any PHP package: it writes
 * configuration and creates database tables, and if it stays reachable after
 * setup it is a complete takeover. Two protections:
 *
 *   1. It refuses to run once a configuration file exists, unless that file has
 *      no database name — i.e. an abandoned half-install.
 *   2. On success it tells the operator to delete it, and the health check and
 *      security check both report its presence as critical.
 */

// ── Guard ────────────────────────────────────────────────────────────────────

define('INSTALLER_ROOT', dirname(__DIR__));

$envPath = INSTALLER_ROOT . '/.env';

/** Read an existing .env into an array, if there is one. */
function readEnvFile(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }

    $values = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);

        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'")
            && $value[strlen($value) - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        }

        $values[trim($key)] = $value;
    }

    return $values;
}

$existing = readEnvFile($envPath);
$alreadyInstalled = ($existing['DB_NAME'] ?? '') !== '';

// ── Helpers ──────────────────────────────────────────────────────────────────

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

/** Requirements the application cannot run without. */
function requirements(): array
{
    return [
        ['label' => 'PHP 8.1 or newer', 'ok' => PHP_VERSION_ID >= 80100, 'detail' => PHP_VERSION],
        ['label' => 'pdo_mysql extension', 'ok' => extension_loaded('pdo_mysql'), 'detail' => ''],
        ['label' => 'curl extension', 'ok' => extension_loaded('curl'), 'detail' => ''],
        ['label' => 'mbstring extension', 'ok' => extension_loaded('mbstring'), 'detail' => ''],
        ['label' => 'json extension', 'ok' => extension_loaded('json'), 'detail' => ''],
        [
            'label'  => 'Application directory is writable',
            'ok'     => is_writable(INSTALLER_ROOT),
            'detail' => INSTALLER_ROOT,
        ],
        [
            'label'  => 'storage/ is writable',
            'ok'     => is_dir(INSTALLER_ROOT . '/storage') && is_writable(INSTALLER_ROOT . '/storage'),
            'detail' => 'Logs and cache are written here',
        ],
    ];
}

$requirements = requirements();
$requirementsMet = true;

foreach ($requirements as $requirement) {
    $requirementsMet = $requirementsMet && $requirement['ok'];
}

// ── Submission ───────────────────────────────────────────────────────────────

$errors = [];
$done   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled && $requirementsMet) {
    $dbHost = post('db_host', 'localhost');
    $dbName = post('db_name');
    $dbUser = post('db_user');
    $dbPass = $_POST['db_pass'] ?? '';
    $prefix = post('db_prefix', 'hoai_') ?: 'hoai_';

    $appUrl   = rtrim(post('app_url'), '/');
    $adminPw  = $_POST['admin_password'] ?? '';
    $provider = post('provider', 'deepseek');
    $apiKey   = trim((string) ($_POST['api_key'] ?? ''));

    if ($dbName === '' || $dbUser === '') {
        $errors[] = 'Database name and username are required.';
    }

    if ($adminPw === '' || strlen($adminPw) < 8) {
        $errors[] = 'Choose an admin password of at least 8 characters.';
    }

    if (!in_array($provider, ['deepseek', 'claude', 'openai'], true)) {
        $errors[] = 'Choose a valid AI provider.';
    }

    if ($apiKey === '') {
        $errors[] = 'An API key is required, or the chatbot cannot answer anything.';
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
        $errors[] = 'The table prefix may contain only letters, numbers and underscores.';
    }

    // Verify the credentials before writing anything. Writing a broken .env and
    // then failing is worse than not writing at all.
    $pdo = null;

    if ($errors === []) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbName),
                $dbUser,
                is_string($dbPass) ? $dbPass : '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (Throwable $exception) {
            // The driver message can include the username; show the plain cause.
            $errors[] = 'Could not connect to that database. Check the name, username and password. '
                      . 'On cPanel the names usually start with your account name, like '
                      . '"myaccount_chatbot".';
        }
    }

    if ($errors === [] && $pdo instanceof PDO) {
        // Install the schema first, so a failure leaves no configuration behind.
        $schemaFiles = ['install.sql', 'phase3.sql', 'phase5.sql'];

        try {
            foreach ($schemaFiles as $file) {
                $sql = (string) file_get_contents(INSTALLER_ROOT . '/database/schema/' . $file);

                if ($prefix !== 'hoai_') {
                    $sql = str_replace('`hoai_', '`' . $prefix, $sql);
                }

                foreach (preg_split('/;\s*[\r\n]+/', $sql) ?: [] as $statement) {
                    $statement = trim($statement);

                    if ($statement === '' || str_starts_with($statement, '--')) {
                        continue;
                    }

                    $pdo->exec($statement);
                }
            }
        } catch (Throwable $exception) {
            $errors[] = 'The database tables could not be created: ' . $exception->getMessage();
        }
    }

    if ($errors === []) {
        $appKey = bin2hex(random_bytes(32));
        $hash   = password_hash($adminPw, PASSWORD_DEFAULT);

        $keyLine = match ($provider) {
            'claude'   => 'ANTHROPIC_API_KEY="' . $apiKey . '"',
            'openai'   => 'OPENAI_API_KEY="' . $apiKey . '"',
            default    => 'DEEPSEEK_API_KEY="' . $apiKey . '"',
        };

        $env = <<<ENV
        # Written by the installer on {DATE}.
        # Keep this file private. See .env.example for every available option.

        APP_NAME="AI Support Chatbot"
        APP_ENV=production
        APP_DEBUG=false
        APP_URL={APP_URL}
        APP_TIMEZONE=UTC
        APP_KEY={APP_KEY}

        DB_HOST={DB_HOST}
        DB_PORT=3306
        DB_NAME={DB_NAME}
        DB_USER={DB_USER}
        DB_PASS="{DB_PASS}"
        DB_CHARSET=utf8mb4
        DB_PREFIX={DB_PREFIX}

        # WordPress and WHMCS are optional. Leave the names blank to skip them;
        # add SELECT-only MySQL users when you are ready.
        WP_DB_HOST=localhost
        WP_DB_NAME=
        WP_DB_USER=
        WP_DB_PASS=
        WP_TABLE_PREFIX=wp_

        WHMCS_DB_HOST=localhost
        WHMCS_DB_NAME=
        WHMCS_DB_USER=
        WHMCS_DB_PASS=

        {KEY_LINE}
        DEFAULT_PROVIDER={PROVIDER}

        RATE_LIMIT_ENABLED=true
        RATE_LIMIT_MAX_REQUESTS=20
        RATE_LIMIT_WINDOW=60

        LOG_LEVEL=info
        LOG_RETENTION_DAYS=30

        ADMIN_PASSWORD_HASH="{ADMIN_HASH}"

        # Spend alerts. Set a budget once you know your normal usage.
        COST_DAILY_BUDGET=0
        COST_HOURLY_BUDGET=0
        COST_HARD_STOP=false
        ENV;

        $env = str_replace(
            ['{DATE}', '{APP_URL}', '{APP_KEY}', '{DB_HOST}', '{DB_NAME}', '{DB_USER}',
             '{DB_PASS}', '{DB_PREFIX}', '{KEY_LINE}', '{PROVIDER}', '{ADMIN_HASH}'],
            [gmdate('Y-m-d H:i:s') . ' UTC', $appUrl, $appKey, $dbHost, $dbName, $dbUser,
             is_string($dbPass) ? $dbPass : '', $prefix, $keyLine, $provider, $hash],
            $env
        );

        // The heredoc above is indented for readability; strip that indentation.
        $env = preg_replace('/^        /m', '', $env) ?? $env;

        if (@file_put_contents($envPath, $env) === false) {
            $errors[] = 'Could not write the .env file. Make the application directory writable '
                      . '(chmod 755) and try again.';
        } else {
            @chmod($envPath, 0600);
            $done = true;
        }
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Install — AI Support Chatbot</title>
<style>
*,*::before,*::after{box-sizing:border-box}
body{margin:0;font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
color:#111827;background:#f3f4f6}
.wrap{max-width:640px;margin:0 auto;padding:40px 20px 80px}
h1{font-size:26px;margin:0 0 6px}
.lead{color:#4b5563;margin-top:0}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:22px;margin-bottom:18px}
h2{font-size:17px;margin:0 0 14px}
label{display:block;font-size:13px;font-weight:600;margin-bottom:5px}
input,select{width:100%;font:inherit;padding:10px 12px;border:1px solid #d1d5db;border-radius:9px;background:#fff}
.field{margin-bottom:16px}
.hint{font-size:12px;color:#6b7280;margin-top:5px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:520px){.row{grid-template-columns:1fr}}
button{width:100%;font:inherit;font-weight:600;cursor:pointer;background:#2563eb;color:#fff;border:0;
border-radius:10px;padding:13px;margin-top:6px}
button:hover{background:#1d4ed8}
button[disabled]{background:#9ca3af;cursor:not-allowed}
.alert{padding:12px 15px;border-radius:10px;margin-bottom:16px;font-size:14px}
.alert.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.alert.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.alert.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
ul.req{list-style:none;margin:0;padding:0}
ul.req li{padding:7px 0;border-bottom:1px solid #f3f4f6;display:flex;gap:10px;align-items:baseline}
ul.req li:last-child{border-bottom:0}
.tick{color:#059669;font-weight:700}
.cross{color:#dc2626;font-weight:700}
code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:13px;word-break:break-all}
ol{padding-left:20px}
ol li{margin-bottom:10px}
</style>
</head>
<body>
<div class="wrap">

<h1>AI Support Chatbot</h1>
<p class="lead">Setup takes about two minutes.</p>

<?php if ($alreadyInstalled): ?>

    <div class="alert warn">
        <strong>Already installed.</strong>
        A configuration file with database settings already exists, so the installer has stopped
        to avoid overwriting it.
    </div>

    <div class="card">
        <h2>Delete this file</h2>
        <p>
            The installer can write configuration and create database tables. Leaving it reachable
            is a security risk. Delete <code>public/install.php</code> now, using cPanel's File
            Manager or FTP.
        </p>
        <p>To reinstall from scratch, delete <code>.env</code> first, then re-upload this file.</p>
    </div>

<?php elseif ($done): ?>

    <div class="alert ok"><strong>Installed.</strong> The chatbot is configured and ready.</div>

    <div class="card">
        <h2>Do this now</h2>
        <ol>
            <li>
                <strong>Delete <code>public/install.php</code>.</strong> It can rewrite your
                configuration, so it must not stay on a live site.
            </li>
            <li>
                Sign in to the admin panel at
                <code><?= e(($_POST['app_url'] ?? '') !== '' ? rtrim(post('app_url'), '/') . '/admin' : '/admin') ?></code>
                with the password you just chose.
            </li>
            <li>
                Add your WordPress and WHMCS database details in <strong>Settings</strong> so the
                chatbot can use your own documentation and see customers' accounts. Both are
                optional — it works without them.
            </li>
            <li>
                Open <strong>Knowledge base</strong> and press <strong>Re-index</strong> to pull in
                your WordPress content.
            </li>
            <li>Add the widget to your site — the snippet is in the README.</li>
        </ol>
    </div>

<?php elseif (!$requirementsMet): ?>

    <div class="alert error">
        <strong>This server cannot run the chatbot yet.</strong>
        Fix the items marked below, then reload this page.
    </div>

    <div class="card">
        <h2>Requirements</h2>
        <ul class="req">
            <?php foreach ($requirements as $requirement): ?>
                <li>
                    <span class="<?= $requirement['ok'] ? 'tick' : 'cross' ?>">
                        <?= $requirement['ok'] ? '&check;' : '&times;' ?>
                    </span>
                    <span>
                        <?= e($requirement['label']) ?>
                        <?php if ($requirement['detail'] !== ''): ?>
                            <div class="hint"><?= e($requirement['detail']) ?></div>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="hint">
            Most cPanel accounts let you change the PHP version under
            <strong>MultiPHP Manager</strong>, and enable extensions under
            <strong>Select PHP Version</strong>.
        </p>
    </div>

<?php else: ?>

    <?php if ($errors !== []): ?>
        <div class="alert error">
            <?php foreach ($errors as $error): ?>
                <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Requirements</h2>
        <ul class="req">
            <?php foreach ($requirements as $requirement): ?>
                <li>
                    <span class="tick">&check;</span>
                    <span><?= e($requirement['label']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <form method="post">
        <div class="card">
            <h2>Database</h2>
            <p class="hint" style="margin-top:-8px">
                Create one in cPanel under <strong>MySQL&nbsp;Databases</strong>, add a user, and
                give that user All Privileges on it. This is the chatbot's own database — not your
                WordPress or WHMCS one.
            </p>

            <div class="field">
                <label for="db_host">Host</label>
                <input type="text" id="db_host" name="db_host" value="<?= e(post('db_host', 'localhost')) ?>">
                <div class="hint">Almost always <code>localhost</code> on cPanel.</div>
            </div>

            <div class="row">
                <div class="field">
                    <label for="db_name">Database name</label>
                    <input type="text" id="db_name" name="db_name" required value="<?= e(post('db_name')) ?>"
                           placeholder="myaccount_chatbot">
                </div>
                <div class="field">
                    <label for="db_user">Database user</label>
                    <input type="text" id="db_user" name="db_user" required value="<?= e(post('db_user')) ?>"
                           placeholder="myaccount_chatbot">
                </div>
            </div>

            <div class="row">
                <div class="field">
                    <label for="db_pass">Database password</label>
                    <input type="password" id="db_pass" name="db_pass" autocomplete="off">
                </div>
                <div class="field">
                    <label for="db_prefix">Table prefix</label>
                    <input type="text" id="db_prefix" name="db_prefix" value="<?= e(post('db_prefix', 'hoai_')) ?>">
                    <div class="hint">Lets the chatbot share a database safely.</div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>AI provider</h2>
            <p class="hint" style="margin-top:-8px">
                You need one key to start. DeepSeek is the cheapest and handles ordinary support
                questions well; you can add Claude later for account actions.
            </p>

            <div class="field">
                <label for="provider">Provider</label>
                <select id="provider" name="provider">
                    <option value="deepseek" <?= post('provider', 'deepseek') === 'deepseek' ? 'selected' : '' ?>>
                        DeepSeek — cheapest
                    </option>
                    <option value="claude" <?= post('provider') === 'claude' ? 'selected' : '' ?>>
                        Claude — best for account actions
                    </option>
                    <option value="openai" <?= post('provider') === 'openai' ? 'selected' : '' ?>>
                        OpenAI
                    </option>
                </select>
            </div>

            <div class="field">
                <label for="api_key">API key</label>
                <input type="password" id="api_key" name="api_key" required autocomplete="off">
                <div class="hint">Stored in your .env file. Never shown again after this.</div>
            </div>
        </div>

        <div class="card">
            <h2>Your access</h2>

            <div class="field">
                <label for="app_url">Website address</label>
                <input type="text" id="app_url" name="app_url" value="<?= e(post('app_url')) ?>"
                       placeholder="https://support.example.com">
                <div class="hint">Where this chatbot is installed, without a trailing slash.</div>
            </div>

            <div class="field">
                <label for="admin_password">Admin password</label>
                <input type="password" id="admin_password" name="admin_password" required
                       minlength="8" autocomplete="new-password">
                <div class="hint">
                    For the admin panel at <code>/admin</code>. At least 8 characters; only its hash
                    is stored.
                </div>
            </div>
        </div>

        <button type="submit">Install</button>
    </form>

<?php endif; ?>

</div>
</body>
</html>
