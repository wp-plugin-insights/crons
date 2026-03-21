<?php

declare(strict_types=1);

/**
 * Data-access layer for the plugin_upload table.
 *
 * Used by the upload API to insert and update upload records, and by the
 * validate-plugins cron to retry publishing pending uploads to RabbitMQ.
 */
class UploadRepository
{
    public function __construct(private readonly mysqli $db)
    {
    }

    /**
     * Inserts a new upload record with status 'pending'.
     *
     * @param array{
     *     plugin_slug: string|null,
     *     plugin_name: string|null,
     *     plugin_version: string|null,
     *     plugin_author: string|null,
     *     plugin_requires: string|null,
     *     plugin_tested: string|null,
     *     plugin_requires_php: string|null,
     *     plugin_description: string|null,
     * } $meta Parsed plugin metadata from ZipExtractor.
     */
    public function insert(string $uuid, string $ip, array $meta, string $extractPath): void
    {
        $status = 'pending';

        $stmt = $this->db->prepare(
            'INSERT INTO plugin_upload
                (upload_uuid, upload_ip, plugin_slug, plugin_name, plugin_version,
                 plugin_author, plugin_requires, plugin_tested, plugin_requires_php,
                 plugin_description, upload_path, upload_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'ssssssssssss',
            $uuid,
            $ip,
            $meta['plugin_slug'],
            $meta['plugin_name'],
            $meta['plugin_version'],
            $meta['plugin_author'],
            $meta['plugin_requires'],
            $meta['plugin_tested'],
            $meta['plugin_requires_php'],
            $meta['plugin_description'],
            $extractPath,
            $status
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Updates the status of an upload record.
     *
     * @param string $status One of: 'pending', 'queued', 'done', 'error'.
     */
    public function updateStatus(string $uuid, string $status, ?string $error = null): void
    {
        if ($error !== null) {
            $stmt = $this->db->prepare(
                'UPDATE plugin_upload
                 SET upload_status = ?, upload_error = ?
                 WHERE upload_uuid = ?'
            );
            $stmt->bind_param('sss', $status, $error, $uuid);
        } else {
            $stmt = $this->db->prepare(
                'UPDATE plugin_upload
                 SET upload_status = ?
                 WHERE upload_uuid = ?'
            );
            $stmt->bind_param('ss', $status, $uuid);
        }

        $stmt->execute();
        $stmt->close();
    }

    /**
     * Returns a single upload record by UUID, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM plugin_upload WHERE upload_uuid = ?'
        );
        $stmt->bind_param('s', $uuid);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Returns the most recent $limit upload records, newest first.
     *
     * Used by the admin panel to display a live overview of API activity.
     *
     * @return list<array<string, mixed>>
     */
    public function getRecent(int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT upload_uuid, upload_ip, plugin_name, plugin_slug,
                    plugin_version, upload_status, upload_error, uploaded_at
             FROM plugin_upload
             ORDER BY uploaded_at DESC
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
     */
    public function countRecentByIp(string $ip, int $windowSeconds = 300): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM plugin_upload
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
     * Returns up to $limit upload records with status 'pending'.
     *
     * These are uploads where the initial RabbitMQ publish failed and need
     * to be retried by the validate-plugins cron.
     *
     * @return list<array<string, mixed>>
     */
    public function getPendingBatch(int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT upload_uuid, plugin_slug, plugin_version, upload_path
             FROM plugin_upload
             WHERE upload_status = 'pending'
               AND upload_path IS NOT NULL
             ORDER BY uploaded_at
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
