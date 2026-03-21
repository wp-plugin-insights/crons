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
require_once __DIR__ . '/src/CronLogger.php';

use PluginInsight\CronLogger;
use PluginInsight\Migrations;
use PluginInsight\PluginSync;
use PluginInsight\WpPluginFetcher;

/** Current schema version. Increment when migrations are added. */
const DB_VERSION = '2.1.0';

/** Plugins per API page (WordPress.org maximum). */
const PER_PAGE = 200;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

$db = db_connect();
$migrations = new Migrations($db);
$migrations->run(DB_VERSION);

$fetcher = new WpPluginFetcher('updated', [
    'versions'        => 1,
    'author'          => 1,
    'author_profile'  => 0,
    'description'     => 0,
    'rating'          => 1,
    'ratings'         => 0,
    'downloaded'      => 1,
    'download_link'   => 1,
    'last_updated'    => 1,
    'active_installs' => 1,
    'icons'           => 1,
    'tags'            => 0,
    'donate_link'     => 0,
]);
$syncer = new PluginSync($db);
$logger = new CronLogger($db, 'fetch-new-plugins');
$runId  = $logger->start();

// ---------------------------------------------------------------------------
// Fetch page 1 and sync
// ---------------------------------------------------------------------------

$inserted  = 0;
$updated   = 0;
$unchanged = 0;

try {
    $data = $fetcher->fetchPage(1, PER_PAGE);

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

    $logger->finish($runId, $inserted + $updated + $unchanged);
} catch (Throwable $e) {
    $logger->fail($runId, $e->getMessage());
    fprintf(STDERR, "[ERROR] %s\n", $e->getMessage());
    exit(1);
}
