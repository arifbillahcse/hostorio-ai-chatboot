<?php

declare(strict_types=1);

/**
 * Build the distributable zip.
 *
 *   php tools/package.php            # build dist/chatbot-<version>.zip
 *   php tools/package.php --manifest # also emit the update manifest JSON
 *
 * What is excluded, and why:
 *
 *   .git, dist          development artefacts
 *   .env                the developer's own secrets — shipping this would hand
 *                       every customer the same API keys
 *   storage/*           logs and cache, which are per-install
 *
 * The archive contains a single top-level directory, which is what the updater
 * expects when it strips one level during extraction.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Hostorio\Core\Version;

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The zip extension is required to build a package.\n");
    exit(1);
}

$version = Version::code();
$name    = 'ai-support-chatbot';
$distDir = HOAI_ROOT . '/dist';
$zipPath = $distDir . '/' . $name . '-' . $version . '.zip';

if (!is_dir($distDir) && !mkdir($distDir, 0755, true)) {
    fwrite(STDERR, "Could not create dist/\n");
    exit(1);
}

@unlink($zipPath);

$zip = new ZipArchive();

if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Could not create {$zipPath}\n");
    exit(1);
}

$excludedDirs  = ['.git', 'dist', 'node_modules', '.github'];
$excludedFiles = ['.env', '.DS_Store'];

$added = 0;

$add = function (string $path) use (&$add, $zip, $name, $excludedDirs, $excludedFiles, &$added): void {
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $full     = $path . '/' . $entry;
        $relative = substr($full, strlen(HOAI_ROOT) + 1);

        if (is_dir($full)) {
            if (in_array($entry, $excludedDirs, true)) {
                continue;
            }

            // storage/ ships as empty directories: the structure is needed, the
            // contents are per-install.
            if (str_starts_with($relative, 'storage/')) {
                $zip->addEmptyDir($name . '/' . $relative);
                continue;
            }

            $add($full);
            continue;
        }

        if (in_array($entry, $excludedFiles, true)) {
            continue;
        }

        if (str_starts_with($relative, 'storage/') && $entry !== '.gitkeep' && $entry !== '.htaccess') {
            continue;
        }

        $zip->addFile($full, $name . '/' . $relative);
        $added++;
    }
};

$add(HOAI_ROOT);
$zip->addEmptyDir($name . '/storage/logs');
$zip->addEmptyDir($name . '/storage/cache');
$zip->close();

$checksum = hash_file('sha256', $zipPath);
$size     = filesize($zipPath);

printf("\nBuilt %s\n", $zipPath);
printf("  version   %s\n", $version);
printf("  files     %d\n", $added);
printf("  size      %s\n", $size === false ? '?' : number_format($size / 1024, 1) . ' KB');
printf("  sha256    %s\n\n", $checksum);

if (in_array('--manifest', $argv, true)) {
    $manifest = [
        'version' => $version,
        'url'     => 'https://YOUR-DOMAIN/releases/' . basename($zipPath),
        'sha256'  => $checksum,
        'min_php' => '8.1',
        'notes'   => 'Describe what changed in this release.',
    ];

    $manifestPath = $distDir . '/manifest.json';
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    printf("Wrote %s — edit the url and notes, then publish it over HTTPS.\n\n", $manifestPath);
}
