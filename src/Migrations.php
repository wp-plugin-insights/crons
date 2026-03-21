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

        if (version_compare($stored, '1.3.0', '<')) {
            $this->migrate130();
        }

        if (version_compare($stored, '1.4.0', '<')) {
            $this->migrate140();
        }

        if (version_compare($stored, '1.5.0', '<')) {
            $this->migrate150();
        }

        if (version_compare($stored, '1.6.0', '<')) {
            $this->migrate160();
        }

        if (version_compare($stored, '1.7.0', '<')) {
            $this->migrate170();
        }

        if (version_compare($stored, '1.8.0', '<')) {
            $this->migrate180();
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
     * 1.7.0 — Admin panel infrastructure.
     *
     * Adds user_is_admin flag to the user table, a key-value site_setting table
     * for runtime configuration (API active/hostname), and a runner table to
     * track RabbitMQ consumer workers with their active/inactive state.
     */
    private function migrate170(): void
    {
        // user_is_admin flag
        $this->db->query(
            "ALTER TABLE `user`
             ADD COLUMN IF NOT EXISTS `user_is_admin`
                 tinyint(1) unsigned NOT NULL DEFAULT 0"
        );

        // Site settings key-value store
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `site_setting` (
                `setting_key`   varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `setting_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
                `updated_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        // Default settings (INSERT IGNORE = safe to re-run)
        $this->db->query(
            "INSERT IGNORE INTO `site_setting` (setting_key, setting_value) VALUES
                ('api_active',   '1'),
                ('api_hostname', 'api.plugininsight.com')"
        );

        // Runner table: one row per RabbitMQ consumer worker
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `runner` (
                `runner_id`        int unsigned NOT NULL AUTO_INCREMENT,
                `runner_name`      varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                `runner_slug`      varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `runner_queue`     varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `runner_is_active` tinyint(1) unsigned NOT NULL DEFAULT 1,
                `created_at`       datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`runner_id`),
                UNIQUE KEY `uq_runner_slug` (`runner_slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        // Ensure all required columns exist — handles tables created with an earlier schema
        $this->db->query(
            "ALTER TABLE `runner`
             ADD COLUMN IF NOT EXISTS `runner_slug`
                 varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''
                 AFTER `runner_name`"
        );
        $this->db->query(
            "ALTER TABLE `runner`
             ADD COLUMN IF NOT EXISTS `runner_queue`
                 varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''
                 AFTER `runner_slug`"
        );
        $this->db->query(
            "ALTER TABLE `runner`
             ADD COLUMN IF NOT EXISTS `runner_is_active`
                 tinyint(1) unsigned NOT NULL DEFAULT 1
                 AFTER `runner_queue`"
        );
        $this->db->query(
            "ALTER TABLE `runner`
             ADD COLUMN IF NOT EXISTS `created_at`
                 datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
                 AFTER `runner_is_active`"
        );

        // Remove rows with empty slugs left over from an earlier/incomplete schema
        $this->db->query("DELETE FROM `runner` WHERE `runner_slug` = ''");

        // Add unique index on runner_slug if missing
        $this->db->query(
            "CREATE UNIQUE INDEX IF NOT EXISTS `uq_runner_slug` ON `runner` (`runner_slug`)"
        );

        // Default runners (INSERT IGNORE = safe to re-run)
        $this->db->query(
            "INSERT IGNORE INTO `runner` (runner_name, runner_slug, runner_queue) VALUES
                ('AI Analysis',       'ai',       'plugin.analysis.ai'),
                ('Basic Analysis',    'basic',    'plugin.analysis.basic'),
                ('Security Analysis', 'security', 'plugin.analysis.security')"
        );
    }

    /**
     * 1.8.0 — Create pluginresult table for storing per-runner analysis results.
     *
     * Each row stores one runner's full JSON output for a specific plugin version.
     * The result column has a JSON_VALID check constraint.
     * FKs reference plugin and runner; no unique constraint — a runner may produce
     * multiple results over time for the same version.
     */
    private function migrate180(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `pluginresult` (
                `plugin_id`           bigint(20) unsigned NOT NULL,
                `plugin_version`      varchar(250)
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `runner_id`           int(10) unsigned NOT NULL,
                `pluginresult_result` longtext
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
                    CHECK (json_valid(`pluginresult_result`)),
                `pluginresult_date`   datetime NOT NULL,
                KEY `plugin_id` (`plugin_id`),
                KEY `runner_id` (`runner_id`),
                KEY `plugin_version` (`plugin_version`),
                KEY `pluginresult_date` (`pluginresult_date`),
                CONSTRAINT `pluginresult_ibfk_1`
                    FOREIGN KEY (`plugin_id`) REFERENCES `plugin` (`plugin_id`),
                CONSTRAINT `pluginresult_ibfk_2`
                    FOREIGN KEY (`runner_id`) REFERENCES `runner` (`runner_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * 1.6.0 — Create plugin_upload table for plugins submitted via the upload API.
     *
     * Each row represents a single ZIP upload. Records are standalone (not linked
     * to the main plugin/plugin_version tables) and identified by a UUID used in
     * public-facing URLs.
     */
    private function migrate160(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `plugin_upload` (
                `upload_id`          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `upload_uuid`        char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `upload_ip`          varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `plugin_slug`        varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                `plugin_name`        varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
                `plugin_version`     varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                `plugin_author`      varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
                `plugin_requires`    varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                `plugin_tested`      varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                `plugin_requires_php` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                `plugin_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
                `upload_path`        varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                `upload_status`      enum('pending','queued','done','error') NOT NULL DEFAULT 'pending',
                `upload_error`       text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
                `uploaded_at`        datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `processed_at`       datetime DEFAULT NULL,
                PRIMARY KEY (`upload_id`),
                UNIQUE KEY `uq_upload_uuid` (`upload_uuid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * 1.5.0 — Add plugin_version_path to store the extracted plugin directory.
     */
    private function migrate150(): void
    {
        $this->db->query(
            "ALTER TABLE `plugin_version`
             ADD COLUMN IF NOT EXISTS `plugin_version_path` varchar(500)
                 CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL"
        );
    }

    /**
     * 1.4.0 — Add author/homepage/icons columns to plugin; create plugin_version table.
     *
     * plugin_version stores every known release ZIP and its optional test date,
     * replacing the plugin_testdate field on the parent table.
     */
    private function migrate140(): void
    {
        $this->db->query(
            "ALTER TABLE `plugin`
                 ADD COLUMN IF NOT EXISTS `plugin_author`
                     varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS `plugin_author_profile`
                     varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS `plugin_homepage`
                     varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS `plugin_short_description`
                     text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
                 ADD COLUMN IF NOT EXISTS `plugin_icons`
                     text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL"
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `plugin_version` (
                `plugin_id`              bigint(20) unsigned NOT NULL,
                `plugin_version`         varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `plugin_version_zip`     varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                `plugin_version_tested`  datetime DEFAULT NULL,
                PRIMARY KEY (`plugin_id`, `plugin_version`),
                CONSTRAINT `plugin_version_ibfk_1`
                    FOREIGN KEY (`plugin_id`) REFERENCES `plugin` (`plugin_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * 1.3.0 — Add plugin_source column to track the origin of each plugin record.
     */
    private function migrate130(): void
    {
        $this->db->query(
            "ALTER TABLE `plugin`
             ADD COLUMN IF NOT EXISTS `plugin_source` varchar(250)
                 CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL"
        );
    }

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
