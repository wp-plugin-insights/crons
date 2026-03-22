<?php

declare(strict_types=1);

namespace PluginInsight;

use mysqli;

/**
 * Data-access layer for the plugin_upload table.
 *
 * plugin_upload is a slim tracking table that links each API upload event
 * (identified by a UUID) to a row in the plugin + plugin_version tables.
 * Plugin metadata lives in plugin/plugin_version; only the UUID, IP address,
 * status, path and timestamps are stored here.
 *
 * Used by the upload API to insert tracking records, and by the
 * validate-plugins cron to retry publishing pending uploads to RabbitMQ.
 */
class UploadRepository
{
    /**
     * @param mysqli $db Active database connection.
     */
    public function __construct(private readonly mysqli $db)
    {
    }

    /**
     * Inserts a new upload tracking record with status 'pending'.
     *
     * @param string $uuid      UUID v4 that uniquely identifies this upload event.
     * @param string $ip        IP address of the uploader.
     * @param int    $pluginId  ID of the plugin row (source='api') in the plugin table.
     * @param string $version   Plugin version string (e.g. "1.2.3").
     * @param string $path      Absolute path to the extracted plugin directory.
     */
    public function insert(
        string $uuid,
        string $ip,
        int $pluginId,
        string $version,
        string $path
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO `plugin_upload`
                 (upload_uuid, upload_ip, plugin_id, plugin_version, upload_path, upload_status)
             VALUES (?, ?, ?, ?, ?, 'pending')"
        );
        $stmt->bind_param('ssiss', $uuid, $ip, $pluginId, $version, $path);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Updates the status (and optionally the error message) of an upload record.
     *
     * @param string      $uuid   UUID of the upload to update.
     * @param string      $status New status: 'pending', 'queued', 'done', or 'error'.
     * @param string|null $error  Optional error message; only stored when $status is 'error'.
     */
    public function updateStatus(string $uuid, string $status, ?string $error = null): void
    {
        $trackProcessed = in_array($status, ['done', 'error'], true);

        if ($error !== null) {
            $sql = $trackProcessed
                ? 'UPDATE `plugin_upload`
                   SET upload_status = ?, upload_error = ?, processed_at = NOW()
                   WHERE upload_uuid = ?'
                : 'UPDATE `plugin_upload`
                   SET upload_status = ?, upload_error = ?
                   WHERE upload_uuid = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('sss', $status, $error, $uuid);
        } else {
            $sql = $trackProcessed
                ? 'UPDATE `plugin_upload`
                   SET upload_status = ?, processed_at = NOW()
                   WHERE upload_uuid = ?'
                : 'UPDATE `plugin_upload` SET upload_status = ? WHERE upload_uuid = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('ss', $status, $uuid);
        }

        $stmt->execute();
        $stmt->close();
    }

    /**
     * Returns a single upload tracking record by UUID, joined with plugin metadata.
     *
     * Returns null if no upload exists for the given UUID.
     *
     * The returned array includes all plugin_upload columns plus the following
     * from the plugin table: plugin_slug, plugin_name, plugin_author,
     * plugin_requires, plugin_tested, plugin_requires_php, and
     * plugin_short_description (aliased as plugin_description).
     *
     * @param  string $uuid UUID of the upload to look up.
     *
     * @return array<string, mixed>|null Row from plugin_upload LEFT JOIN plugin, or null.
     */
    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT pu.upload_uuid,
                    pu.upload_ip,
                    pu.plugin_id,
                    pu.plugin_version,
                    pu.upload_path,
                    pu.upload_status,
                    pu.upload_error,
                    pu.uploaded_at,
                    pu.processed_at,
                    p.plugin_slug,
                    p.plugin_name,
                    p.plugin_author,
                    p.plugin_requires,
                    p.plugin_tested,
                    p.plugin_requires_php,
                    p.plugin_short_description AS plugin_description
             FROM `plugin_upload` pu
             LEFT JOIN `plugin` p ON p.plugin_id = pu.plugin_id
             WHERE pu.upload_uuid = ?'
        );
        $stmt->bind_param('s', $uuid);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Returns the most recent $limit upload tracking records, newest first.
     *
     * Joins with the plugin table to include slug and name.
     * Used by the admin panel to display a live overview of API upload activity.
     *
     * @param  int $limit Maximum number of rows to return.
     *
     * @return list<array<string, mixed>>
     */
    public function getRecentForAdmin(int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT pu.upload_uuid,
                    pu.upload_ip,
                    pu.plugin_version,
                    pu.upload_status,
                    pu.upload_error,
                    pu.uploaded_at,
                    p.plugin_name,
                    p.plugin_slug
             FROM `plugin_upload` pu
             LEFT JOIN `plugin` p ON p.plugin_id = pu.plugin_id
             ORDER BY pu.uploaded_at DESC
             LIMIT ?'
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();

        $result = $stmt->get_result();
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * Returns the number of uploads from the given IP within the last $windowSeconds.
     *
     * Used to enforce per-IP rate limits on the web upload form.
     *
     * @param string $ip            Uploader IP address.
     * @param int    $windowSeconds Look-back window in seconds (default 300 = 5 min).
     *
     * @return int Number of uploads within the window.
     */
    public function countRecentByIp(string $ip, int $windowSeconds = 300): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM `plugin_upload`
             WHERE upload_ip = ?
               AND uploaded_at > DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt->bind_param('si', $ip, $windowSeconds);
        $stmt->execute();

        $count = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();

        return $count;
    }

    /**
     * Resets an upload back to 'pending' so the validate-plugins cron will
     * re-publish it to RabbitMQ on the next run.
     *
     * Only acts when the current status is 'queued' or 'error' and the
     * upload_path is still present (directory exists on disk).
     *
     * @param string $uuid UUID of the upload to requeue.
     */
    public function requeueByUuid(string $uuid): void
    {
        $stmt = $this->db->prepare(
            "UPDATE `plugin_upload`
             SET upload_status = 'pending', upload_error = NULL
             WHERE upload_uuid = ?
               AND upload_status IN ('queued', 'error')
               AND upload_path IS NOT NULL"
        );
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Returns up to $limit uploads that are stuck in 'queued' status with no
     * analysis results yet.
     *
     * An upload is considered stuck when its status is 'queued' and no row
     * exists in pluginresult for the same plugin_id + plugin_version pair.
     * Results are ordered oldest first so the longest-waiting items appear at
     * the top.
     *
     * @param  int $limit Maximum number of rows to return.
     *
     * @return list<array<string, mixed>>
     */
    public function getStuckQueued(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT pu.upload_uuid,
                    pu.plugin_id,
                    pu.plugin_version,
                    pu.upload_status,
                    pu.uploaded_at,
                    p.plugin_name,
                    p.plugin_slug
             FROM `plugin_upload` pu
             LEFT JOIN `plugin` p ON p.plugin_id = pu.plugin_id
             WHERE pu.upload_status = 'queued'
               AND NOT EXISTS (
                   SELECT 1 FROM `pluginresult` pr
                   WHERE pr.plugin_id = pu.plugin_id
                     AND pr.plugin_version = pu.plugin_version
               )
             ORDER BY pu.uploaded_at
             LIMIT ?"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();

        $result = $stmt->get_result();
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * Returns up to $limit upload tracking records with status 'pending'.
     *
     * These are uploads where the initial RabbitMQ publish failed and need
     * to be retried by the validate-plugins cron. Only rows with a resolved
     * plugin_id and a non-null upload_path are returned.
     *
     * Joins with plugin to provide the slug for the RabbitMQ message.
     *
     * @param  int $limit Maximum number of rows to return.
     *
     * @return list<array<string, mixed>>
     */
    public function getPendingBatch(int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT pu.upload_uuid,
                    pu.plugin_version,
                    pu.upload_path,
                    p.plugin_slug
             FROM `plugin_upload` pu
             JOIN `plugin` p ON p.plugin_id = pu.plugin_id
             WHERE pu.upload_status = 'pending'
               AND pu.upload_path IS NOT NULL
               AND pu.plugin_id IS NOT NULL
             ORDER BY pu.uploaded_at
             LIMIT ?"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();

        $result = $stmt->get_result();
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}
