<?php

/**
 * validate-plugins.php
 *
 * Processes a batch of plugin versions pending validation:
 *   1. Downloads the release ZIP from WordPress.org.
 *   2. Extracts it to a unique directory under EXTRACT_DIR.
 *   3. Validates the readme.txt "Stable tag:" against the expected version.
 *   4. Updates plugin_version_path and plugin_version_tested in the database.
 *   5. Publishes a persistent JSON message to the RabbitMQ queue (if available).
 *
 * On failure a version is left untouched (plugin_version_tested remains NULL)
 * so the next run retries it.
 *
 * If RabbitMQ is unavailable the script continues: versions are validated and
 * marked in the database, but no message is published. The upload retry section
 * at the bottom also requires a live publisher and is skipped when unavailable.
 *
 * Usage:
 *   php validate-plugins.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../dbcon.php';
require_once __DIR__ . '/rabbitmq.php';
require_once __DIR__ . '/src/Migrations.php';
require_once __DIR__ . '/src/ZipExtractor.php';
require_once __DIR__ . '/src/PluginValidator.php';
require_once __DIR__ . '/src/RabbitMqPublisher.php';
require_once __DIR__ . '/src/UploadRepository.php';
require_once __DIR__ . '/src/CronLogger.php';

use PluginInsight\CronLogger;
use PluginInsight\Migrations;
use PluginInsight\PluginValidator;
use PluginInsight\RabbitMqPublisher;
use PluginInsight\UploadRepository;

/** Current schema version. */
const DB_VERSION = '2.1.0';

/** Number of plugin versions processed per run. */
const BATCH_SIZE = 10;

/** Directory where ZIP files are downloaded (deleted after extraction). */
const ZIP_DIR = '/webs/plugininsight/zipfiles';

/** Directory where plugin files are extracted for analysis. */
const EXTRACT_DIR = '/webs/plugininsight/extracted';

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

$db = db_connect();
$migrations = new Migrations($db);
$migrations->run(DB_VERSION);

$logger = new CronLogger($db, 'validate-plugins');
$runId  = $logger->start();

// ---------------------------------------------------------------------------
// Connect to RabbitMQ (optional — validation proceeds without it)
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
    fwrite(STDERR, '[validate-plugins] RabbitMQ unavailable: ' . $e->getMessage() . PHP_EOL);
}

// ---------------------------------------------------------------------------
// Process batch
// ---------------------------------------------------------------------------

$ok     = 0;
$failed = 0;

try {
    $validator = new PluginValidator($db, ZIP_DIR, EXTRACT_DIR);
    $pending   = $validator->getPendingBatch(BATCH_SIZE);

    if (empty($pending)) {
        echo "No pending plugin versions to validate.\n";
    } else {
        printf("Processing %d plugin version(s)...\n\n", count($pending));

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
    }

    // -------------------------------------------------------------------------
    // Retry pending API uploads (RabbitMQ publish may have failed at upload time)
    // -------------------------------------------------------------------------

    if ($publisher === null) {
        fwrite(STDERR, "[WARN]  Skipping upload retries — RabbitMQ publisher unavailable.\n");
    } else {
        $uploadRepo     = new UploadRepository($db);
        $pendingUploads = $uploadRepo->getPendingBatch(BATCH_SIZE);

        if (!empty($pendingUploads)) {
            printf("\nRetrying %d pending upload(s)...\n\n", count($pendingUploads));

            foreach ($pendingUploads as $upload) {
                $label = $upload['upload_uuid'];

                try {
                    $publisher->publish([
                        'plugin'  => $upload['plugin_slug'] ?? $upload['upload_uuid'],
                        'source'  => 'api-upload',
                        'version' => $upload['plugin_version'] ?? 'unknown',
                        'src'     => $upload['upload_path'],
                        'uuid'    => $upload['upload_uuid'],
                    ]);
                    $uploadRepo->updateStatus($upload['upload_uuid'], 'queued');
                    printf("[OK]    %s\n", $label);
                } catch (Throwable $e) {
                    fprintf(STDERR, "[ERROR] %s: %s\n", $label, $e->getMessage());
                }
            }
        }
    }

    $logger->finish($runId, $ok);
} catch (Throwable $e) {
    $logger->fail($runId, $e->getMessage());
    fprintf(STDERR, "[FATAL] %s\n", $e->getMessage());
    exit(1);
}
