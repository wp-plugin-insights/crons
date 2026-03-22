<?php

/**
 * fetch-wp-locales.php
 *
 * Fetches WordPress locale metadata from the WordPress.org translations API
 * and upserts one row per language code into the `wp_locale` table.
 *
 * Fields stored: locale_language (PK), locale_english_name, locale_native_name.
 *
 * Designed to run once per week via a systemd timer.
 *
 * Usage:
 *   php8.4 fetch-wp-locales.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../dbcon.php';
require_once __DIR__ . '/src/Migrations.php';
require_once __DIR__ . '/src/WpLocalesFetcher.php';
require_once __DIR__ . '/src/CronLogger.php';

use PluginInsight\CronLogger;
use PluginInsight\Migrations;
use PluginInsight\WpLocalesFetcher;

/** Current schema version. Must match the other cron entry-points. */
const DB_VERSION = '2.2.0';

// ── Bootstrap ────────────────────────────────────────────────────────────────

$db = db_connect();
$migrations = new Migrations($db);
$migrations->run(DB_VERSION);

// ── Run ──────────────────────────────────────────────────────────────────────

$fetcher = new WpLocalesFetcher($db);
$logger  = new CronLogger($db, 'fetch-wp-locales');
$runId   = $logger->start();

try {
    $fetcher->run();
    $logger->finish($runId, 1);
} catch (RuntimeException $e) {
    $logger->fail($runId, $e->getMessage());
    fwrite(STDERR, '[fetch-wp-locales] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
