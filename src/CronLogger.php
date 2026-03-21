<?php

declare(strict_types=1);

namespace PluginInsight;

use mysqli;

/**
 * Records the start, finish, and failure of each cron script execution.
 *
 * Usage pattern inside every cron entry point:
 *
 *   $logger = new CronLogger($db, 'fetch-new-plugins');
 *   $runId  = $logger->start();
 *   try {
 *       // … main logic …
 *       $logger->finish($runId, $itemCount);
 *   } catch (\Throwable $e) {
 *       $logger->fail($runId, $e->getMessage());
 *       exit(1);
 *   }
 *
 * Rows that remain in 'running' status after their expected schedule interval
 * indicate a crash or abnormal termination and are surfaced in the admin panel.
 */
class CronLogger
{
    /** Nanosecond timestamp captured by start() for sub-second duration tracking. */
    private int $startNs = 0;

    /**
     * @param mysqli $db       Active database connection.
     * @param string $cronName Identifier matching the systemd service name
     *                         without the "plugininsight-" prefix and ".php"
     *                         suffix, e.g. "fetch-new-plugins".
     */
    public function __construct(
        private readonly mysqli $db,
        private readonly string $cronName
    ) {
    }

    /**
     * Inserts a new 'running' row and returns its primary key.
     *
     * Must be called once at the very start of the script, before any work
     * is performed. The returned ID must be passed to finish() or fail().
     *
     * @return int The cron_run_id. Returns 0 if the INSERT fails (non-fatal).
     */
    public function start(): int
    {
        $this->startNs = hrtime(true);

        $stmt = $this->db->prepare(
            "INSERT INTO `cron_run` (cron_name, started_at, status)
             VALUES (?, NOW(), 'running')"
        );

        if ($stmt === false) {
            return 0;
        }

        $cronName = $this->cronName;
        $stmt->bind_param('s', $cronName);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Marks a run as successfully completed.
     *
     * No-op when $runId is 0 (start() failed to insert).
     *
     * @param int $runId          The ID returned by start().
     * @param int $itemsProcessed Number of items processed (plugins, versions, etc.).
     */
    public function finish(int $runId, int $itemsProcessed = 0): void
    {
        if ($runId === 0) {
            return;
        }

        $durationMs = $this->elapsedMs();

        $stmt = $this->db->prepare(
            "UPDATE `cron_run`
             SET finished_at     = NOW(),
                 duration_ms     = ?,
                 status          = 'ok',
                 items_processed = ?
             WHERE cron_run_id = ?"
        );

        if ($stmt === false) {
            return;
        }

        $stmt->bind_param('iii', $durationMs, $itemsProcessed, $runId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Marks a run as failed and records the error message.
     *
     * No-op when $runId is 0 (start() failed to insert).
     *
     * @param int    $runId        The ID returned by start().
     * @param string $errorMessage The error message to persist.
     */
    public function fail(int $runId, string $errorMessage): void
    {
        if ($runId === 0) {
            return;
        }

        $durationMs = $this->elapsedMs();

        $stmt = $this->db->prepare(
            "UPDATE `cron_run`
             SET finished_at   = NOW(),
                 duration_ms   = ?,
                 status        = 'error',
                 error_message = ?
             WHERE cron_run_id = ?"
        );

        if ($stmt === false) {
            return;
        }

        $stmt->bind_param('isi', $durationMs, $errorMessage, $runId);
        $stmt->execute();
        $stmt->close();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the elapsed time in milliseconds since start() was called.
     *
     * @return int Elapsed milliseconds, clamped to 0 when start() was not called.
     */
    private function elapsedMs(): int
    {
        if ($this->startNs === 0) {
            return 0;
        }

        return (int) round((hrtime(true) - $this->startNs) / 1_000_000);
    }
}
