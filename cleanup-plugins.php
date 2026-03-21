<?php

/**
 * cleanup-plugins.php
 *
 * Deletes extracted plugin directories that were created more than EXPIRE_HOURS
 * ago, freeing disk space. Uses plugin_version_tested as the reference timestamp
 * since it is set at the same moment the directory is created during validation.
 *
 * After deletion, plugin_version_path is set to NULL so the version can be
 * re-extracted if needed.
 *
 * Designed to run every hour via a systemd timer.
 *
 * Usage:
 *   php cleanup-plugins.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../dbcon.php';
require_once __DIR__ . '/src/Migrations.php';
require_once __DIR__ . '/src/PluginCleaner.php';

/** Current schema version. */
const DB_VERSION = '1.5.0';

/** Directories older than this many hours will be deleted. */
const EXPIRE_HOURS = 6;

/** Maximum number of directories removed per run. */
const CLEANUP_BATCH = 500;

// ---------------------------------------------------------------------------
// Process
// ---------------------------------------------------------------------------

$migrations = new Migrations($db);
$migrations->run(DB_VERSION);

$cleaner = new PluginCleaner($db);
$expired = $cleaner->getExpiredBatch(EXPIRE_HOURS, CLEANUP_BATCH);

if (empty($expired)) {
    echo "Nothing to clean up.\n";
    exit(0);
}

printf("Removing %d expired director%s...\n", count($expired), count($expired) === 1 ? 'y' : 'ies');

$ok     = 0;
$failed = 0;

foreach ($expired as $row) {
    try {
        $cleaner->cleanup($row);
        printf("[OK]    %s\n", $row['plugin_version_path']);
        $ok++;
    } catch (Throwable $e) {
        fprintf(STDERR, "[ERROR] %s: %s\n", $row['plugin_version_path'], $e->getMessage());
        $failed++;
    }
}

printf("\nDone. Removed: %d | Failed: %d\n", $ok, $failed);
