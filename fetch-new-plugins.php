<?php

/**
 * fetch-new-plugins.php
 *
 * Fetches page 1 (the most recently added plugins) from the WordPress.org API
 * and synchronizes them into the local `plugin` table.
 *
 * Designed to run every 5 minutes via a systemd timer.
 *
 * Usage:
 *   php fetch-new-plugins.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../dbcon.php';
require_once __DIR__ . '/src/Migrations.php';
require_once __DIR__ . '/src/WpPluginFetcher.php';
require_once __DIR__ . '/src/PluginSync.php';

/** Current schema version. Increment when migrations are added. */
const DB_VERSION = '1.4.0';

/** Plugins per API page (WordPress.org maximum). */
const PER_PAGE = 200;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

$migrations = new Migrations($db);
$migrations->run(DB_VERSION);

$fetcher = new WpPluginFetcher();
$syncer  = new PluginSync($db);

// ---------------------------------------------------------------------------
// Fetch page 1 and sync
// ---------------------------------------------------------------------------

$inserted  = 0;
$updated   = 0;
$unchanged = 0;

try {
    $data = $fetcher->fetchPage(1, PER_PAGE);
} catch (RuntimeException $e) {
    fprintf(STDERR, "[ERROR] %s\n", $e->getMessage());
    exit(1);
}

foreach ($data['plugins'] as $plugin) {
    $upsert = $syncer->upsert($plugin);

    match ($upsert['result']) {
        'inserted'  => ++$inserted,
        'updated'   => ++$updated,
        default     => ++$unchanged,
    };

    if ($upsert['plugin_id'] > 0 && !empty($plugin['versions'])) {
        $syncer->syncVersions($upsert['plugin_id'], $plugin['versions']);
    }
}

printf(
    "Done. Inserted: %d | Updated: %d | Unchanged: %d\n",
    $inserted,
    $updated,
    $unchanged
);
