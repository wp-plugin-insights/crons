<?php

declare(strict_types=1);

/**
 * Removes extracted plugin directories that are older than a given number of hours.
 *
 * Uses plugin_version_tested as the creation timestamp of the extracted directory,
 * since both are set at the same moment during validation. After deletion,
 * plugin_version_path is set to NULL so the record can be re-extracted if needed.
 */
class PluginCleaner
{
    public function __construct(private readonly mysqli $db)
    {
    }

    /**
     * Returns plugin versions whose extracted directory is older than $hours hours.
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
     * Deletes the extracted directory and clears plugin_version_path in the database.
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
