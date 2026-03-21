<?php

/**
 * fetch-all-plugins.php
 *
 * Full synchronization cron: iterates every page of the WordPress.org Plugins
 * API (browse=new, ordered by date added, newest first) and upserts all plugins
 * and their version history into the local database.
 *
 * Designed to run once per day via a systemd timer.
 *
 * Pagination terminates when the API returns an empty plugins array.
 * The WordPress.org API reports `info.results` as the total plugin count;
 * we derive the expected page count from that to avoid iterating forever if
 * the API misbehaves.
 *
 * Usage:
 *   php fetch-all-plugins.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../dbcon.php';
require_once __DIR__ . '/src/Migrations.php';
require_once __DIR__ . '/src/WpPluginFetcher.php';
require_once __DIR__ . '/src/PluginSync.php';

/** Must match or exceed the version declared in fetch-new-plugins.php. */
const DB_VERSION = '1.4.0';

/** Plugins per API page (WordPress.org maximum). */
const PER_PAGE = 200;

/** Log a progress line every N pages. */
const LOG_EVERY_PAGES = 10;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

$migrations = new Migrations($db);
$migrations->run(DB_VERSION);

$fetcher = new WpPluginFetcher('new', [
    'versions'    => 1,
    'ratings'     => 0,
    'description' => 0,
]);
$syncer = new PluginSync($db);

// ---------------------------------------------------------------------------
// Paginate through the entire API
// ---------------------------------------------------------------------------

$page      = 1;
$maxPages  = PHP_INT_MAX;
$inserted  = 0;
$updated   = 0;
$unchanged = 0;
$errors    = 0;

printf("[%s] Starting full sync\n", date('Y-m-d H:i:s'));

while ($page <= $maxPages) {
    try {
        $data = $fetcher->fetchPage($page, PER_PAGE);
    } catch (RuntimeException $e) {
        fprintf(STDERR, "[ERROR] Page %d: %s\n", $page, $e->getMessage());
        $errors++;
        // Retry logic: skip the page and continue rather than aborting the run.
        $page++;
        continue;
    }

    // On the first page, derive the real upper bound from the total result count.
    if ($page === 1) {
        $total    = (int) $data['info']['results'];
        $maxPages = (int) ceil($total / PER_PAGE);
        printf("[%s] Total plugins: %d — expected pages: %d\n", date('Y-m-d H:i:s'), $total, $maxPages);
    }

    if (empty($data['plugins'])) {
        printf("[%s] Empty page %d — stopping.\n", date('Y-m-d H:i:s'), $page);
        break;
    }

    $pageInserted  = 0;
    $pageUpdated   = 0;

    foreach ($data['plugins'] as $plugin) {
        $upsert = $syncer->upsert($plugin);

        match ($upsert['result']) {
            'inserted' => ++$pageInserted,
            'updated'  => ++$pageUpdated,
            default    => ++$unchanged,
        };

        if ($upsert['plugin_id'] > 0 && !empty($plugin['versions'])) {
            $syncer->syncVersions($upsert['plugin_id'], $plugin['versions']);
        }
    }

    $inserted += $pageInserted;
    $updated  += $pageUpdated;

    if ($page % LOG_EVERY_PAGES === 0 || $pageInserted > 0 || $pageUpdated > 0) {
        printf(
            "[%s] Page %d/%d — inserted: %d, updated: %d\n",
            date('Y-m-d H:i:s'),
            $page,
            $maxPages,
            $pageInserted,
            $pageUpdated
        );
    }

    $page++;
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

printf(
    "\n[%s] Done. Inserted: %d | Updated: %d | Unchanged: %d | Errors: %d\n",
    date('Y-m-d H:i:s'),
    $inserted,
    $updated,
    $unchanged,
    $errors
);
