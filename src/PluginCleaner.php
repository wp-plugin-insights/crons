<?php

declare(strict_types=1);

namespace PluginInsight;

use FilesystemIterator;
use mysqli;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Removes extracted plugin directories that are older than a given number of hours.
 *
 * Uses plugin_version_tested as the creation timestamp of the extracted directory,
 * since both are set at the same moment during validation. After deletion,
 * plugin_version_path is set to NULL so the record can be re-extracted if needed.
 */
class PluginCleaner
{
    /**
     * @param mysqli $db Active database connection.
     */
    public function __construct(private readonly mysqli $db)
    {
    }

    /**
     * Returns plugin versions whose extracted directory is older than $hours hours.
     *
     * Only rows with a non-null plugin_version_path are returned, since those
     * are the only ones backed by a directory on disk.
     *
     * @param  int $hours Maximum age in hours before a directory is considered expired.
     * @param  int $limit Maximum number of rows to return per batch.
     *
     * @return list<array{plugin_id: int, plugin_version: string, plugin_version_path: string}>
     */
    public function getExpiredBatch(int $hours, int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT plugin_id, plugin_version, plugin_version_path
             FROM plugin_version
             WHERE plugin_version_path   IS NOT NULL
               AND plugin_version_tested < NOW() - INTERVAL ? HOUR
             LIMIT ?'
        );
        $stmt->bind_param('ii', $hours, $limit);
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
     * Deletes the extracted directory for the given row and clears its path in the database.
     *
     * If the directory does not exist on disk (already deleted by another process),
     * the database update is still performed so the row is not retried.
     *
     * @param array{plugin_id: int, plugin_version: string, plugin_version_path: string} $row
     */
    public function cleanup(array $row): void
    {
        $path = $row['plugin_version_path'];

        if (is_dir($path)) {
            $this->deleteDirectory($path);
        }

        $stmt = $this->db->prepare(
            'UPDATE plugin_version
             SET plugin_version_path = NULL
             WHERE plugin_id = ? AND plugin_version = ?'
        );
        $stmt->bind_param('is', $row['plugin_id'], $row['plugin_version']);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Recursively deletes a directory and all its contents.
     *
     * Files and subdirectories are removed depth-first (children before parents).
     * Individual removal failures are silently ignored — partial cleanup is
     * preferable to an aborted run.
     *
     * @param string $path Absolute path to the directory to delete.
     */
    private function deleteDirectory(string $path): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
