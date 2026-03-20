<?php

declare(strict_types=1);

/**
 * Handles database schema versioning and migrations.
 *
 * Stores the current schema version in `plugin_schema_meta` and applies
 * pending migrations in order when the stored version is behind DB_VERSION.
 */
class Migrations
{
    private const META_TABLE = 'plugin_schema_meta';

    public function __construct(private readonly mysqli $db)
    {
        $this->ensureMetaTable();
    }

    /**
     * Runs all pending migrations up to $targetVersion.
     */
    public function run(string $targetVersion): void
    {
        $stored = $this->getStoredVersion();

        if (version_compare($stored, $targetVersion, '>=')) {
            return;
        }

        if (version_compare($stored, '1.1.0', '<')) {
            $this->migrate110();
        }

        if (version_compare($stored, '1.2.0', '<')) {
            $this->migrate120();
        }

        $this->setVersion($targetVersion);
    }

    /**
     * Returns the schema version currently stored in the database.
     */
    public function getStoredVersion(): string
    {
        $result = $this->db->query(
            "SELECT meta_value FROM `" . self::META_TABLE . "` WHERE meta_key = 'db_version'"
        );

        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;

        return $row['meta_value'] ?? '1.0.0';
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Creates the meta table if it does not already exist.
     */
    private function ensureMetaTable(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . self::META_TABLE . "` (
                `meta_key`   varchar(100) NOT NULL,
                `meta_value` varchar(250) NOT NULL,
                PRIMARY KEY (`meta_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * Persists the given schema version to the meta table.
     */
    private function setVersion(string $version): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `" . self::META_TABLE . "` (meta_key, meta_value)
             VALUES ('db_version', ?)
             ON DUPLICATE KEY UPDATE meta_value = ?"
        );
        $stmt->bind_param('ss', $version, $version);
        $stmt->execute();
        $stmt->close();
    }

    // -------------------------------------------------------------------------
    // Migration routines (one method per version, never modified)
    // -------------------------------------------------------------------------

    /**
     * 1.1.0 — Add plugin_zip column to store the latest version download URL.
     */
    private function migrate110(): void
    {
        $this->db->query(
            "ALTER TABLE `plugin`
             ADD COLUMN IF NOT EXISTS `plugin_zip` varchar(500)
                 CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL"
        );
    }

    /**
     * 1.2.0 — Add extended metadata columns sourced from the WordPress.org API.
     */
    private function migrate120(): void
    {
        $this->db->query(
            "ALTER TABLE `plugin`
                 ADD COLUMN IF NOT EXISTS `plugin_name`
                     varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS `plugin_requires`
                     varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS `plugin_tested`
                     varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS `plugin_requires_php`
                     varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS `plugin_requires_plugins`
                     text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS `plugin_rating`
                     tinyint unsigned DEFAULT 0,
                 ADD COLUMN IF NOT EXISTS `plugin_num_ratings`
                     int unsigned DEFAULT 0,
                 ADD COLUMN IF NOT EXISTS `plugin_support_threads`
                     int unsigned DEFAULT 0,
                 ADD COLUMN IF NOT EXISTS `plugin_support_threads_resolved`
                     int unsigned DEFAULT 0,
                 ADD COLUMN IF NOT EXISTS `plugin_downloaded`
                     bigint unsigned DEFAULT 0,
                 ADD COLUMN IF NOT EXISTS `plugin_last_updated`
                     datetime DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS `plugin_added`
                     date DEFAULT NULL"
        );
    }
}
