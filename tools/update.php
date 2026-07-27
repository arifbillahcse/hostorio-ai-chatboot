<?php

declare(strict_types=1);

/**
 * Update the chatbot.
 *
 *   php tools/update.php --check     What version is available?
 *   php tools/update.php --migrate   Apply pending database migrations only
 *   php tools/update.php --apply     Download, verify, install, migrate
 *   php tools/update.php --rollback  Restore the previous version
 *
 * Design notes
 * ------------
 * Self-updating code is dangerous, so three things are non-negotiable here:
 *
 *  1. **The download is verified against a SHA-256 in the manifest, and the
 *     manifest is fetched over HTTPS with certificate verification on.** An
 *     unverified updater is a remote code execution feature: whoever controls
 *     the network between the customer and the update server controls the site.
 *
 *  2. **The previous version is backed up before anything is replaced**, and
 *     `--rollback` restores it. A shared host can kill the process mid-extract;
 *     without a backup that leaves a half-updated install with no way back.
 *
 *  3. **Configuration and data are never touched.** `.env`, `storage/` and
 *     anything the customer added survive an update untouched.
 *
 * Migrations run *after* files are in place, because new code usually needs the
 * new schema. They are separately re-runnable so an interrupted update can be
 * finished with `--migrate`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Hostorio\Core\Config;
use Hostorio\Core\Logger;
use Hostorio\Core\Version;
use Hostorio\Database\AppDatabase;

$flags = [];

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/i', $argument, $m) === 1) {
        $flags[strtolower($m[1])] = $m[2] ?? 'true';
    }
}

/**
 * Paths preserved across an update, relative to the application root.
 *
 * VERSION is deliberately NOT here: it must be replaced by the new release, or
 * the install would keep reporting the old version and --check would offer the
 * same update forever.
 */
const PRESERVE = ['.env', 'storage'];

function out(string $message): void
{
    echo $message . "\n";
}

function fail(string $message): never
{
    fwrite(STDERR, "\n" . $message . "\n\n");
    exit(1);
}

// ── Migrations ───────────────────────────────────────────────────────────────

/**
 * Apply every migration above the recorded schema version.
 *
 * @return array{applied: int, from: int, to: int}
 */
function runMigrations(): array
{
    $migrations = require HOAI_ROOT . '/database/migrations.php';
    ksort($migrations);

    $db      = AppDatabase::instance();
    $current = Version::schema();
    $startedAt = $current;
    $applied = 0;

    foreach ($migrations as $version => $migration) {
        if ($version <= $current) {
            continue;
        }

        out(sprintf('  applying %d: %s', $version, $migration['description'] ?? ''));

        foreach ($migration['statements'] ?? [] as $statement) {
            $statement = str_replace('{prefix}', $db->prefix(), $statement);

            try {
                $db->execute($statement);
            } catch (Throwable $e) {
                $ignorable = false;

                foreach ($migration['ignore_errors'] ?? [] as $fragment) {
                    if (str_contains($e->getMessage(), $fragment)) {
                        $ignorable = true;
                        break;
                    }
                }

                if (!$ignorable) {
                    fail(sprintf(
                        "Migration %d failed and was stopped:\n  %s\n\n"
                        . "The schema is still recorded at version %d, so this can be retried "
                        . "with: php tools/update.php --migrate",
                        $version,
                        $e->getMessage(),
                        $current
                    ));
                }

                out('    (already applied)');
            }
        }

        // Recorded per migration, not once at the end: an interruption then
        // resumes from the last completed step rather than repeating them all.
        Version::setSchema((int) $version);
        $current = (int) $version;
        $applied++;
    }

    return ['applied' => $applied, 'from' => $startedAt, 'to' => $current];
}

// ── Manifest ─────────────────────────────────────────────────────────────────

/**
 * Fetch the update manifest.
 *
 * @return array{version: string, url: string, sha256: string, notes?: string, min_php?: string}
 */
function fetchManifest(): array
{
    $url = (string) Config::get('update.manifest_url', '');

    if ($url === '') {
        fail("No update source is configured.\n"
           . 'Set UPDATE_MANIFEST_URL in your .env to the URL your provider gave you.');
    }

    if (!str_starts_with($url, 'https://')) {
        // Plain HTTP would let anyone on the path serve their own package.
        fail('The update URL must use https:// — refusing to fetch updates over an insecure connection.');
    }

    $handle = curl_init();

    curl_setopt_array($handle, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'Hostorio-AI-Chatbot-Updater/' . Version::code(),
    ]);

    $body   = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error  = curl_error($handle);

    curl_close($handle);

    if ($body === false || $status !== 200) {
        fail(sprintf('Could not fetch the update manifest (HTTP %d). %s', $status, $error));
    }

    $manifest = json_decode((string) $body, true);

    if (!is_array($manifest) || !isset($manifest['version'], $manifest['url'], $manifest['sha256'])) {
        fail('The update manifest is malformed. It needs version, url and sha256.');
    }

    return $manifest;
}

// ── Commands ─────────────────────────────────────────────────────────────────

$current = Version::code();

if (isset($flags['check'])) {
    out("\nInstalled version: {$current}");
    out('Schema version:    ' . Version::schema());

    $manifest = fetchManifest();

    out('Available version: ' . $manifest['version']);

    if (Version::isNewer((string) $manifest['version'], $current)) {
        out("\nAn update is available.");

        if (isset($manifest['notes'])) {
            out("\n" . $manifest['notes']);
        }

        out("\nInstall it with: php tools/update.php --apply\n");
    } else {
        out("\nYou are up to date.\n");
    }

    exit(0);
}

if (isset($flags['migrate'])) {
    out("\nApplying database migrations…");

    $result = runMigrations();

    out($result['applied'] === 0
        ? "  nothing to do — schema is at version {$result['to']}\n"
        : sprintf("  %d migration(s) applied; schema is now at version %d\n", $result['applied'], $result['to']));

    exit(0);
}

if (isset($flags['rollback'])) {
    $backup = HOAI_ROOT . '/storage/backup';

    if (!is_dir($backup) || !is_file($backup . '/VERSION')) {
        fail('There is no backup to roll back to.');
    }

    out("\nRestoring the previous version…");

    restoreTree($backup, HOAI_ROOT);

    out('  restored version ' . trim((string) file_get_contents($backup . '/VERSION')));
    out("\nIf the database was already migrated, the older code may not match the schema.");
    out("Check the admin panel, and contact your provider if anything looks wrong.\n");

    exit(0);
}

if (isset($flags['apply'])) {
    $manifest = fetchManifest();

    if (!Version::isNewer((string) $manifest['version'], $current)) {
        out("\nAlready up to date ({$current}).\n");
        exit(0);
    }

    if (isset($manifest['min_php']) && version_compare(PHP_VERSION, (string) $manifest['min_php'], '<')) {
        fail(sprintf(
            'Version %s needs PHP %s or newer; this server runs %s.',
            $manifest['version'],
            $manifest['min_php'],
            PHP_VERSION
        ));
    }

    out("\nUpdating {$current} -> {$manifest['version']}");

    // 1. Download.
    out('  downloading…');
    $archive = tempnam(sys_get_temp_dir(), 'hoai-update-');

    if ($archive === false) {
        fail('Could not create a temporary file for the download.');
    }

    $target = fopen($archive, 'w');

    if ($target === false) {
        fail('Could not open the temporary download file.');
    }

    $handle = curl_init();

    curl_setopt_array($handle, [
        CURLOPT_URL            => $manifest['url'],
        CURLOPT_FILE           => $target,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);

    $ok     = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

    curl_close($handle);
    fclose($target);

    if ($ok === false || $status !== 200) {
        @unlink($archive);
        fail(sprintf('Download failed (HTTP %d).', $status));
    }

    // 2. Verify. Everything after this point executes code from the archive, so
    //    this check is what stands between the customer and a supply-chain
    //    compromise.
    out('  verifying checksum…');
    $actual = hash_file('sha256', $archive);

    if (!hash_equals(strtolower((string) $manifest['sha256']), strtolower((string) $actual))) {
        @unlink($archive);
        Logger::error('Update checksum mismatch', [
            'expected' => $manifest['sha256'],
            'actual'   => $actual,
        ]);
        fail("Checksum mismatch — the download does not match the manifest.\n"
           . 'Nothing has been changed. Do not retry until your provider confirms the release.');
    }

    if (!class_exists('ZipArchive')) {
        @unlink($archive);
        fail('The zip extension is not available, so the update cannot be extracted. '
           . 'Ask your host to enable it, or update by uploading the files manually.');
    }

    // 3. Back up before touching anything.
    out('  backing up the current version…');
    $backup = HOAI_ROOT . '/storage/backup';

    if (is_dir($backup)) {
        removeTree($backup);
    }

    if (!@mkdir($backup, 0750, true)) {
        @unlink($archive);
        fail('Could not create the backup directory at ' . $backup);
    }

    backupTree(HOAI_ROOT, $backup);

    // 4. Extract over the top, preserving configuration and data.
    out('  installing files…');
    $zip = new ZipArchive();

    if ($zip->open($archive) !== true) {
        @unlink($archive);
        fail('The downloaded archive could not be opened.');
    }

    $extracted = extractPreserving($zip, HOAI_ROOT);
    $zip->close();
    @unlink($archive);

    out(sprintf('  %d file(s) written', $extracted));

    // 5. Migrate.
    out('  applying database migrations…');
    $result = runMigrations();
    out(sprintf('  %d migration(s) applied', $result['applied']));

    Logger::info('Update applied', ['from' => $current, 'to' => $manifest['version']]);

    out("\nUpdated to {$manifest['version']}.");
    out('If anything is wrong, roll back with: php tools/update.php --rollback');
    out("Then run: php tools/security-check.php\n");

    exit(0);
}

// ── Default: status ──────────────────────────────────────────────────────────

out("\nAI Support Chatbot");
out(str_repeat('-', 50));
out('  code version    ' . $current);
out('  schema version  ' . Version::schema());
out('  update source   ' . (Config::get('update.manifest_url', '') ?: '(not configured)'));
out('');
out('  --check     see whether a newer version is available');
out('  --migrate   apply pending database migrations');
out('  --apply     download and install the latest version');
out('  --rollback  restore the previous version');
out('');

// ── File helpers ─────────────────────────────────────────────────────────────

/**
 * Copy the current install into the backup directory, skipping data.
 */
function backupTree(string $from, string $to): void
{
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'storage' || $entry === '.git') {
            continue;
        }

        $source = $from . '/' . $entry;
        $target = $to . '/' . $entry;

        if (is_dir($source)) {
            @mkdir($target, 0750, true);
            backupTree($source, $target);
            continue;
        }

        @copy($source, $target);
    }

    // The version file is copied explicitly so --rollback can report it.
    if (is_file(HOAI_ROOT . '/VERSION')) {
        @copy(HOAI_ROOT . '/VERSION', $to . '/VERSION');
    }
}

function restoreTree(string $from, string $to): void
{
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $source = $from . '/' . $entry;
        $target = $to . '/' . $entry;

        if (is_dir($source)) {
            @mkdir($target, 0750, true);
            restoreTree($source, $target);
            continue;
        }

        @copy($source, $target);
    }
}

function removeTree(string $path): void
{
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path . '/' . $entry;

        is_dir($child) ? removeTree($child) : @unlink($child);
    }

    @rmdir($path);
}

/**
 * Extract an archive over the install without disturbing configuration or data.
 *
 * Also rejects path traversal in archive entries: a malicious `../../` entry
 * would otherwise write outside the application directory.
 */
function extractPreserving(ZipArchive $zip, string $root): int
{
    $written = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if ($name === false || str_ends_with($name, '/')) {
            continue;
        }

        // Archives are built with a single top-level directory; drop it.
        $relative = preg_replace('#^[^/]+/#', '', $name) ?? $name;

        if ($relative === '' || str_contains($relative, '..')) {
            continue;
        }

        foreach (PRESERVE as $preserved) {
            if ($relative === $preserved || str_starts_with($relative, $preserved . '/')) {
                continue 2;
            }
        }

        $target = $root . '/' . $relative;
        $dir    = dirname($target);

        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            continue;
        }

        $contents = $zip->getFromIndex($i);

        if ($contents === false) {
            continue;
        }

        if (@file_put_contents($target, $contents) !== false) {
            $written++;
        }
    }

    return $written;
}
