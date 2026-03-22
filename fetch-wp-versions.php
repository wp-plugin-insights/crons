<?php

/**
 * fetch-wp-versions.php
 *
 * Fetches the WordPress core version list from the WordPress.org API and stores
 * one canonical record per major.minor branch in the `site_setting` table under
 * the key `wp_versions`.
 *
 * The frontend reads this setting to determine whether a plugin's "Tested up to"
 * version is current or outdated, without relying on a hardcoded version string.
 *
 * Designed to run once per hour via a systemd timer.
 *
 * Usage:
 *   php8.4 fetch-wp-versions.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../dbcon.php';
require_once __DIR__ . '/src/Migrations.php';
require_once __DIR__ . '/src/WpVersionFetcher.php';
require_once __DIR__ . '/src/CronLogger.php';

use PluginInsight\CronLogger;
use PluginInsight\Migrations;
use PluginInsight\WpVersionFetcher;

/** Current schema version. Must match the other cron entry-points. */
const DB_VERSION = '2.2.0';

// ── Bootstrap ────────────────────────────────────────────────────────────────

$db = db_connect();
$migrations = new Migrations($db);
$migrations->run(DB_VERSION);

// ── Run ──────────────────────────────────────────────────────────────────────

$fetcher = new WpVersionFetcher($db);
$logger  = new CronLogger($db, 'fetch-wp-versions');
$runId   = $logger->start();

try {
    $fetcher->run();
    $logger->finish($runId, 1);
} catch (RuntimeException $e) {
    $logger->fail($runId, $e->getMessage());
    fwrite(STDERR, '[fetch-wp-versions] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
