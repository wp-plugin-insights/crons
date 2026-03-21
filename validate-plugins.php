<?php

/**
 * validate-plugins.php
 *
 * Processes a batch of plugin versions pending validation:
 *   1. Downloads the release ZIP from WordPress.org.
 *   2. Extracts it to a unique directory under EXTRACT_DIR.
 *   3. Validates the readme.txt "Stable tag:" against the expected version.
 *   4. Updates plugin_version_path and plugin_version_tested in the database.
 *   5. Publishes a JSON message to the RabbitMQ queue (stub: written to QUEUE_LOG).
 *
 * On failure a version is left untouched (plugin_version_tested remains NULL)
 * so the next run retries it.
 *
 * Usage:
 *   php validate-plugins.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../dbcon.php';
require_once __DIR__ . '/src/Migrations.php';
require_once __DIR__ . '/src/PluginValidator.php';
require_once __DIR__ . '/src/RabbitMqPublisher.php';

/** Current schema version. */
const DB_VERSION = '1.5.0';

/** Number of plugin versions processed per run. */
const BATCH_SIZE = 10;

/** Directory where ZIP files are downloaded (deleted after extraction). */
const ZIP_DIR = '/webs/plugininsight/zipfiles';

/** Directory where plugin files are extracted for analysis. */
const EXTRACT_DIR = '/webs/plugininsight/extracted';

/** Log file used by the RabbitMQ stub publisher. */
const QUEUE_LOG = '/webs/plugininsight/logs/rabbitmq-queue.log';

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

$migrations = new Migrations($db);
$migrations->run(DB_VERSION);

$validator = new PluginValidator($db, ZIP_DIR, EXTRACT_DIR);
$publisher = new RabbitMqPublisher(QUEUE_LOG);

// ---------------------------------------------------------------------------
// Process batch
// ---------------------------------------------------------------------------

$pending = $validator->getPendingBatch(BATCH_SIZE);

if (empty($pending)) {
    echo "No pending plugin versions to validate.\n";
    exit(0);
}

printf("Processing %d plugin version(s)...\n\n", count($pending));

$ok     = 0;
$failed = 0;

foreach ($pending as $row) {
    $label = $row['plugin_slug'] . ' ' . $row['plugin_version'];

    try {
        $validator->process($row, $publisher);
        printf("[OK]    %s\n", $label);
        $ok++;
    } catch (RuntimeException $e) {
        fprintf(STDERR, "[ERROR] %s: %s\n", $label, $e->getMessage());
        $failed++;
    }
}

printf("\nDone. OK: %d | Failed: %d\n", $ok, $failed);
