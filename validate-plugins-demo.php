<?php

/**
 * validate-plugins-demo.php
 *
 * Demo script that forces a full re-analysis of Contact Form 7 v6.1.5.
 *
 * What it does:
 *   1. Looks up contact-form-7 / 6.1.5 in the database.
 *   2. Deletes all existing pluginresult rows for that plugin+version so every
 *      runner reruns from scratch.
 *   3. Resets plugin_version_tested and plugin_version_path to NULL so the
 *      normal PluginValidator::process() flow re-downloads and re-extracts.
 *   4. Removes the extracted directory on disk (if present).
 *   5. Runs the standard download → extract → publish pipeline.
 *
 * Usage (manual — no cron):
 *   php8.4 validate-plugins-demo.php
 *
 * Note: the validate-plugins.timer must be stopped before running this script
 * to avoid a race condition with the main queue processor.
 *   systemctl stop plugininsight-validate-plugins.timer
 *   php8.4 validate-plugins-demo.php
 *   systemctl start plugininsight-validate-plugins.timer   # when ready to resume
 */

declare(strict_types=1);

require_once __DIR__ . '/../dbcon.php';
require_once __DIR__ . '/rabbitmq.php';
require_once __DIR__ . '/src/Migrations.php';
require_once __DIR__ . '/src/ZipExtractor.php';
require_once __DIR__ . '/src/PluginValidator.php';
require_once __DIR__ . '/src/RabbitMqPublisher.php';

use PluginInsight\Migrations;
use PluginInsight\PluginValidator;
use PluginInsight\RabbitMqPublisher;

/** Current schema version. */
const DB_VERSION = '2.4.0';

/** Plugin and version to regenerate. */

const DEMO_SLUG    = 'contact-form-7';
const DEMO_VERSION = '6.1.5';

/*
const DEMO_SLUG    = 'wpvulnerability';
const DEMO_VERSION = '4.3.1';
 */

/*
const DEMO_SLUG    = 'classic-editor';
const DEMO_VERSION = '1.6.7';
 */

/*
const DEMO_SLUG    = 'query-monitor';
const DEMO_VERSION = '3.20.4';
 */


/** Directory where ZIP files are downloaded (deleted after extraction). */
const ZIP_DIR = '/webs/plugininsight/zipfiles';

/** Directory where plugin files are extracted for analysis. */
const EXTRACT_DIR = '/webs/plugininsight/extracted';

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

$db = db_connect();
(new Migrations($db))->run(DB_VERSION);

echo "=======================================================\n";
echo " PluginInsight — Demo regeneration\n";
echo " Plugin : " . DEMO_SLUG . "\n";
echo " Version: " . DEMO_VERSION . "\n";
echo "=======================================================\n\n";

// ---------------------------------------------------------------------------
// Step 1 — Look up the plugin+version row
// ---------------------------------------------------------------------------

echo "[1/5] Looking up plugin in database...\n";

$stmt = $db->prepare(
    'SELECT pv.plugin_id, p.plugin_slug, pv.plugin_version, pv.plugin_version_zip,
            pv.plugin_version_path
     FROM plugin_version pv
     JOIN plugin p ON p.plugin_id = pv.plugin_id
     WHERE p.plugin_slug = ?
       AND pv.plugin_version = ?
     LIMIT 1'
);
$stmt->bind_param('ss', $slug, $version);
$slug    = DEMO_SLUG;
$version = DEMO_VERSION;
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row === null) {
    fwrite(STDERR, "[FATAL] Plugin '" . DEMO_SLUG . "' v" . DEMO_VERSION . " not found in database.\n");
    fwrite(STDERR, "        Run fetch-new-plugins.php or fetch-all-plugins.php first.\n");
    exit(1);
}

if (empty($row['plugin_version_zip'])) {
    fwrite(STDERR, "[FATAL] No ZIP URL stored for this version. Cannot proceed.\n");
    exit(1);
}

$pluginId   = (int) $row['plugin_id'];
$extractDir = EXTRACT_DIR . '/' . DEMO_SLUG . '/' . DEMO_VERSION;

printf("       plugin_id : %d\n", $pluginId);
printf("       zip_url   : %s\n", $row['plugin_version_zip']);
printf("       extract   : %s\n\n", $extractDir);

// ---------------------------------------------------------------------------
// Step 2 — Delete existing analysis results
// ---------------------------------------------------------------------------

echo "[2/5] Deleting existing pluginresult rows...\n";

$stmt = $db->prepare(
    'DELETE FROM pluginresult WHERE plugin_id = ? AND plugin_version = ?'
);
$stmt->bind_param('is', $pluginId, $version);
$stmt->execute();
$deleted = $stmt->affected_rows;
$stmt->close();

printf("       Deleted %d result row(s).\n\n", $deleted);

// ---------------------------------------------------------------------------
// Step 3 — Reset plugin_version_tested and plugin_version_path
// ---------------------------------------------------------------------------

echo "[3/5] Resetting plugin_version_tested and plugin_version_path...\n";

$stmt = $db->prepare(
    'UPDATE plugin_version
     SET plugin_version_tested = NULL,
         plugin_version_path   = NULL
     WHERE plugin_id = ? AND plugin_version = ?'
);
$stmt->bind_param('is', $pluginId, $version);
$stmt->execute();
$stmt->close();

echo "       Done.\n\n";

// ---------------------------------------------------------------------------
// Step 4 — Remove extracted directory on disk
// ---------------------------------------------------------------------------

echo "[4/5] Removing extracted directory (if present)...\n";

if (is_dir($extractDir)) {
    $cmd    = 'rm -rf ' . escapeshellarg($extractDir);
    $result = 0;
    passthru($cmd, $result);
    if ($result !== 0) {
        fwrite(STDERR, "[WARN]  Could not remove extracted directory: {$extractDir}\n");
        fwrite(STDERR, "        Extraction will be skipped and existing files reused.\n");
    } else {
        printf("       Removed: %s\n\n", $extractDir);
    }
} else {
    echo "       Directory not found — nothing to remove.\n\n";
}

// ---------------------------------------------------------------------------
// Step 5 — Connect to RabbitMQ, download, extract, and publish
// ---------------------------------------------------------------------------

echo "[5/5] Connecting to RabbitMQ...\n";

$publisher = null;
try {
    $publisher = new RabbitMqPublisher(
        RABBITMQ_HOST,
        RABBITMQ_PORT,
        RABBITMQ_USER,
        RABBITMQ_PASS,
        RABBITMQ_VHOST,
        RABBITMQ_EXCHANGE
    );
    echo "       Connected.\n\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[WARN]  RabbitMQ unavailable: " . $e->getMessage() . "\n");
    fwrite(STDERR, "        The plugin will be extracted but NOT queued for analysis.\n\n");
}

echo "       Downloading, extracting, and publishing...\n";

try {
    $validator = new PluginValidator($db, ZIP_DIR, EXTRACT_DIR);
    $validator->process([
        'plugin_id'          => $pluginId,
        'plugin_slug'        => DEMO_SLUG,
        'plugin_version'     => DEMO_VERSION,
        'plugin_version_zip' => $row['plugin_version_zip'],
    ], $publisher);

    echo "\n=======================================================\n";
    echo " SUCCESS\n";
    echo " " . DEMO_SLUG . " v" . DEMO_VERSION . " has been queued for analysis.\n";
    echo " Results will appear at:\n";
    echo " https://www.plugininsight.com/plugin/" . DEMO_SLUG . "/\n";
    echo "=======================================================\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\n[FATAL] " . $e->getMessage() . "\n");
    exit(1);
}
