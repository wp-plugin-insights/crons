<?php

/**
 * validate-plugins-demo.php
 *
 * Forces a full re-analysis of the demo plugin set. For each entry:
 *   1. Looks up the plugin + version in the database.
 *   2. Deletes all existing pluginresult rows so every runner reruns.
 *   3. Resets plugin_version_tested and plugin_version_path to NULL.
 *   4. Removes the extracted directory from disk (if present).
 *   5. Runs the standard download → extract → publish pipeline.
 *
 * Usage (manual — no cron):
 *   php8.4 validate-plugins-demo.php
 *
 * Note: stop the main queue processor before running to avoid races:
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

/** Directory where ZIP files are downloaded (deleted after extraction). */
const ZIP_DIR = '/webs/plugininsight/zipfiles';

/** Directory where plugin files are extracted for analysis. */
const EXTRACT_DIR = '/webs/plugininsight/extracted';

/**
 * Demo plugin set.
 * Add or remove entries here to control which plugins are regenerated.
 *
 * @var list<array{slug: string, version: string}>
 */
const DEMO_PLUGINS = [
    ['slug' => 'contact-form-7',        'version' => '6.1.5'],
    ['slug' => 'wpvulnerability',        'version' => '4.3.1'],
    ['slug' => 'classic-editor',         'version' => '1.6.7'],
    ['slug' => 'query-monitor',          'version' => '3.20.4'],
    ['slug' => 'woocommerce',            'version' => '10.6.1'],
    ['slug' => 'advanced-custom-fields', 'version' => '6.7.1'],
    ['slug' => 'redirection',            'version' => '5.7.5'],
    ['slug' => 'akismet',                'version' => '5.6'],
    ['slug' => 'elementor',              'version' => '3.35.7'],
];

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

$db = db_connect();
(new Migrations($db))->run(DB_VERSION);

// ---------------------------------------------------------------------------
// Connect to RabbitMQ once — shared across all plugins
// ---------------------------------------------------------------------------

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
} catch (Throwable $e) {
    fwrite(STDERR, "[WARN]  RabbitMQ unavailable: " . $e->getMessage() . "\n");
    fwrite(STDERR, "        Plugins will be extracted but NOT queued for analysis.\n\n");
}

$validator = new PluginValidator($db, ZIP_DIR, EXTRACT_DIR);

$total   = count(DEMO_PLUGINS);
$ok      = 0;
$skipped = 0;
$failed  = 0;

echo "=======================================================\n";
echo " PluginInsight — Demo regeneration ({$total} plugin(s))\n";
echo "=======================================================\n\n";

// ---------------------------------------------------------------------------
// Process each demo plugin
// ---------------------------------------------------------------------------

foreach (DEMO_PLUGINS as $i => $demo) {
    $demoSlug    = $demo['slug'];
    $demoVersion = $demo['version'];
    $n           = $i + 1;

    echo "-------------------------------------------------------\n";
    printf(" [%d/%d] %s  v%s\n", $n, $total, $demoSlug, $demoVersion);
    echo "-------------------------------------------------------\n";

    // Step 1 — Look up plugin+version in database
    echo "[1/5] Looking up plugin in database...\n";

    $stmt = $db->prepare(
        'SELECT pv.plugin_id, pv.plugin_version_zip
         FROM plugin_version pv
         JOIN plugin p ON p.plugin_id = pv.plugin_id
         WHERE p.plugin_slug = ?
           AND pv.plugin_version = ?
         LIMIT 1'
    );
    $stmt->bind_param('ss', $demoSlug, $demoVersion);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row === null) {
        fprintf(STDERR, "[SKIP]  %s v%s not found in database.\n", $demoSlug, $demoVersion);
        fwrite(STDERR, "        Run fetch-new-plugins.php or fetch-all-plugins.php first.\n\n");
        $skipped++;
        continue;
    }

    if (empty($row['plugin_version_zip'])) {
        fprintf(STDERR, "[SKIP]  No ZIP URL for %s v%s. Skipping.\n\n", $demoSlug, $demoVersion);
        $skipped++;
        continue;
    }

    $pluginId   = (int) $row['plugin_id'];
    $extractDir = EXTRACT_DIR . '/' . $demoSlug . '/' . $demoVersion;

    printf("       plugin_id : %d\n", $pluginId);
    printf("       zip_url   : %s\n", $row['plugin_version_zip']);
    printf("       extract   : %s\n\n", $extractDir);

    // Step 2 — Delete existing analysis results
    echo "[2/5] Deleting existing pluginresult rows...\n";

    $stmt = $db->prepare(
        'DELETE FROM pluginresult WHERE plugin_id = ? AND plugin_version = ?'
    );
    $stmt->bind_param('is', $pluginId, $demoVersion);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();

    printf("       Deleted %d result row(s).\n\n", $deleted);

    // Step 3 — Reset plugin_version_tested and plugin_version_path
    echo "[3/5] Resetting plugin_version_tested and plugin_version_path...\n";

    $stmt = $db->prepare(
        'UPDATE plugin_version
         SET plugin_version_tested = NULL,
             plugin_version_path   = NULL
         WHERE plugin_id = ? AND plugin_version = ?'
    );
    $stmt->bind_param('is', $pluginId, $demoVersion);
    $stmt->execute();
    $stmt->close();

    echo "       Done.\n\n";

    // Step 4 — Remove extracted directory on disk
    echo "[4/5] Removing extracted directory (if present)...\n";

    if (is_dir($extractDir)) {
        $rmResult = 0;
        passthru('rm -rf ' . escapeshellarg($extractDir), $rmResult);
        if ($rmResult !== 0) {
            fprintf(STDERR, "[WARN]  Could not remove %s — existing files will be reused.\n\n", $extractDir);
        } else {
            printf("       Removed: %s\n\n", $extractDir);
        }
    } else {
        echo "       Directory not found — nothing to remove.\n\n";
    }

    // Step 5 — Download, extract, publish
    echo "[5/5] Downloading, extracting, and publishing...\n";

    try {
        $validator->process([
            'plugin_id'          => $pluginId,
            'plugin_slug'        => $demoSlug,
            'plugin_version'     => $demoVersion,
            'plugin_version_zip' => $row['plugin_version_zip'],
        ], $publisher);

        printf("\n[OK]    %s v%s queued for analysis.\n\n", $demoSlug, $demoVersion);
        $ok++;
    } catch (Throwable $e) {
        fprintf(STDERR, "\n[ERROR] %s v%s: %s\n\n", $demoSlug, $demoVersion, $e->getMessage());
        $failed++;
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "=======================================================\n";
printf(" Done. OK: %d | Skipped: %d | Failed: %d\n", $ok, $skipped, $failed);
if ($ok > 0) {
    echo " Results will appear at: https://www.plugininsight.com/\n";
}
echo "=======================================================\n";

if ($failed > 0) {
    exit(1);
}
