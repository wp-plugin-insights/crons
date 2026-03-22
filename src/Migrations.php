<?php

declare(strict_types=1);

namespace PluginInsight;

use mysqli;
use mysqli_result;

/**
 * Handles database schema versioning and migrations.
 *
 * Stores the current schema version in `plugin_schema_meta` and applies
 * pending migrations in order when the stored version is behind DB_VERSION.
 *
 * Each migration method is idempotent and must never be modified after
 * it has been deployed to production.
 */
class Migrations
{
    /** Table used to persist the current schema version. */
    private const META_TABLE = 'plugin_schema_meta';

    /**
     * @param mysqli $db Active database connection.
     */
    public function __construct(private readonly mysqli $db)
    {
        $this->ensureMetaTable();
    }

    /**
     * Runs all pending migrations up to $targetVersion.
     *
     * Migrations are applied in ascending version order. Already-applied
     * migrations are skipped. The stored version is updated to $targetVersion
     * when all applicable migrations have run.
     *
     * @param string $targetVersion Highest version to migrate to (e.g. "1.9.0").
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

        if (version_compare($stored, '1.9.0', '<')) {
            $this->migrate190();
        }

        if (version_compare($stored, '2.0.0', '<')) {
            $this->migrate200();
        }

        if (version_compare($stored, '2.1.0', '<')) {
            $this->migrate210();
        }

        if (version_compare($stored, '2.2.0', '<')) {
            $this->migrate220();
        }

        if (version_compare($stored, '2.3.0', '<')) {
            $this->migrate230();
        }

        if (version_compare($stored, '2.4.0', '<')) {
            $this->migrate240();
        }

        $this->setVersion($targetVersion);
    }

    /**
     * Returns the schema version currently stored in the database.
     *
     * Returns "1.0.0" when no version row exists yet (fresh install).
     *
     * @return string Dotted version string, e.g. "1.9.0".
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
     *
     * Called in the constructor so it is always available before any
     * version check or migration is attempted.
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
     *
     * Uses INSERT … ON DUPLICATE KEY UPDATE so it is safe to call on
     * both a fresh install and an existing installation.
     *
     * @param string $version Dotted version string to store, e.g. "1.9.0".
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
     * 2.1.0 — Create wp_php_compat table.
     *
     * Maps each major WordPress version milestone to the minimum PHP version
     * it requires. The frontend uses this to validate that a plugin's declared
     * PHP requirement is not lower than what its declared minimum WP version
     * needs. Rows are pre-seeded from official WordPress release notes.
     * The table uses ON DUPLICATE KEY UPDATE so re-running is safe.
     */
    private function migrate210(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `wp_php_compat` (
                `wp_version`      varchar(20)  NOT NULL,
                `php_min_version` varchar(20)  NOT NULL,
                PRIMARY KEY (`wp_version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
              COMMENT='Minimum PHP version required by each WordPress release milestone'"
        );

        // Seed the known milestone rows (idempotent via ON DUPLICATE KEY UPDATE).
        $seeds = [
            ['2.0',   '4.2'],
            ['2.5',   '4.3'],
            ['3.2',   '5.2.4'],
            ['5.2',   '5.6.20'],
            ['6.3',   '7.0'],
            ['6.6',   '7.2.24'],
        ];

        $stmt = $this->db->prepare(
            'INSERT INTO `wp_php_compat` (wp_version, php_min_version) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE php_min_version = VALUES(php_min_version)'
        );

        if ($stmt === false) {
            return;
        }

        foreach ($seeds as [$wpVer, $phpVer]) {
            $stmt->bind_param('ss', $wpVer, $phpVer);
            $stmt->execute();
        }

        $stmt->close();
    }

    /**
     * 2.0.0 — Create cron_run table for per-script execution history.
     *
     * One row is inserted when a cron script starts (status = 'running') and
     * updated to 'ok' or 'error' when it finishes. Rows stuck in 'running'
     * indicate a crash or abnormal exit. The admin panel reads the last 10
     * rows per cron name to display health statistics.
     */
    private function migrate200(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `cron_run` (
                `cron_run_id`      bigint unsigned     NOT NULL AUTO_INCREMENT,
                `cron_name`        varchar(64)
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `started_at`       datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `finished_at`      datetime            DEFAULT NULL,
                `duration_ms`      int unsigned        DEFAULT NULL
                    COMMENT 'Wall-clock milliseconds from start to finish',
                `status`           enum('running','ok','error')
                    NOT NULL DEFAULT 'running',
                `items_processed`  int unsigned        NOT NULL DEFAULT 0,
                `error_message`    text
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
                PRIMARY KEY (`cron_run_id`),
                KEY `idx_cron_name_started` (`cron_name`, `started_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * 2.2.0 — Allow pluginresult rows for API-uploaded plugins.
     *
     * API-uploaded plugins (plugin_upload) are not registered in the plugin table,
     * so plugin_id cannot be populated when runners produce results for them.
     *
     * Changes:
     *  - plugin_id becomes nullable (was NOT NULL).
     *  - upload_uuid CHAR(36) nullable column added, with FK to plugin_upload.
     *  - CHECK constraint ensures exactly one of plugin_id / upload_uuid is set.
     *
     * The foreign-key constraint on plugin_id is recreated as DEFERRABLE-style
     * nullable: rows with plugin_id NOT NULL still require a matching plugin row.
     */
    private function migrate220(): void
    {
        // Drop the NOT NULL constraint on plugin_id (MariaDB requires a full MODIFY).
        $this->db->query(
            "ALTER TABLE `pluginresult`
             MODIFY `plugin_id` bigint(20) unsigned DEFAULT NULL"
        );

        // Add upload_uuid column if it does not already exist.
        $this->db->query(
            "ALTER TABLE `pluginresult`
             ADD COLUMN IF NOT EXISTS `upload_uuid` char(36)
                 CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
                 AFTER `plugin_id`"
        );

        // Add FK from upload_uuid to plugin_upload.
        $this->db->query(
            "ALTER TABLE `pluginresult`
             ADD CONSTRAINT `pluginresult_ibfk_3`
             FOREIGN KEY (`upload_uuid`) REFERENCES `plugin_upload` (`upload_uuid`)"
        );

        // Add index on upload_uuid for result lookups by UUID.
        $this->db->query(
            "ALTER TABLE `pluginresult`
             ADD INDEX IF NOT EXISTS `upload_uuid` (`upload_uuid`)"
        );

        // Enforce that exactly one source identifier is present.
        $this->db->query(
            "ALTER TABLE `pluginresult`
             ADD CONSTRAINT `chk_pluginresult_source`
             CHECK (
                 (`plugin_id` IS NOT NULL AND `upload_uuid` IS NULL)
                 OR
                 (`plugin_id` IS NULL AND `upload_uuid` IS NOT NULL)
             )"
        );
    }

    /**
     * 1.9.0 — Create wp_locale table for WordPress locale metadata.
     *
     * Populated weekly by crons/fetch-wp-locales.php from the WordPress.org
     * translations API. One row per language code (e.g. "en_US", "es", "fr").
     * Used by the frontend to display human-readable language names.
     */
    private function migrate190(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `wp_locale` (
                `locale_language`    varchar(20)
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `locale_english_name` varchar(150)
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                `locale_native_name`  varchar(150)
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
                `locale_synced_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`locale_language`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    /**
     * 2.3.0 — Integrate API uploads into the standard plugin/plugin_version tables.
     *
     * Changes:
     *  - pluginresult: reverts migration 2.2.0 — drops upload_uuid column, its FK,
     *    index, and CHECK constraint; restores plugin_id to NOT NULL.
     *  - plugin_upload: becomes a slim tracking table — adds plugin_id FK column;
     *    legacy metadata columns are retained for historical rows.
     *  - Data migration: for each existing plugin_upload row, inserts the plugin
     *    into the plugin table (source='api') and a plugin_version row, then sets
     *    plugin_upload.plugin_id to the resolved plugin_id.
     *  - Adds index and FK on plugin_upload.plugin_id.
     */
    private function migrate230(): void
    {
        // ── 1. Revert pluginresult changes from 2.2.0 ────────────────────────

        $this->db->query(
            "ALTER TABLE `pluginresult`
             DROP CONSTRAINT IF EXISTS `chk_pluginresult_source`"
        );

        $this->db->query(
            "ALTER TABLE `pluginresult`
             DROP FOREIGN KEY IF EXISTS `pluginresult_ibfk_3`"
        );

        $this->db->query(
            "ALTER TABLE `pluginresult`
             DROP INDEX IF EXISTS `upload_uuid`"
        );

        $this->db->query(
            "ALTER TABLE `pluginresult`
             DROP COLUMN IF EXISTS `upload_uuid`"
        );

        // Restore NOT NULL (safe: confirmed 0 rows with NULL plugin_id in production).
        $this->db->query(
            "ALTER TABLE `pluginresult`
             MODIFY `plugin_id` bigint(20) unsigned NOT NULL"
        );

        // ── 2. Add plugin_id FK column to plugin_upload ───────────────────────

        $this->db->query(
            "ALTER TABLE `plugin_upload`
             ADD COLUMN IF NOT EXISTS `plugin_id`
                 bigint(20) unsigned DEFAULT NULL
                 AFTER `upload_ip`"
        );

        // ── 3. Migrate existing plugin_upload rows → plugin + plugin_version ──

        $result = $this->db->query(
            "SELECT upload_id, plugin_slug, plugin_name, plugin_version,
                    plugin_author, plugin_requires, plugin_tested,
                    plugin_requires_php, plugin_description, upload_path
             FROM `plugin_upload`
             WHERE plugin_id IS NULL
               AND plugin_slug IS NOT NULL
               AND plugin_version IS NOT NULL"
        );

        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                // Insert API plugin (ON DUPLICATE KEY = already exists from a
                // previous partial migration or a collision with another source).
                $stmt = $this->db->prepare(
                    "INSERT INTO `plugin`
                         (plugin_source, plugin_slug, plugin_name, plugin_author,
                          plugin_requires, plugin_tested, plugin_requires_php,
                          plugin_short_description, plugin_version)
                     VALUES ('api', ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                         plugin_name    = COALESCE(VALUES(plugin_name),    plugin_name),
                         plugin_version = COALESCE(VALUES(plugin_version), plugin_version)"
                );
                $stmt->bind_param(
                    'ssssssss',
                    $row['plugin_slug'],
                    $row['plugin_name'],
                    $row['plugin_author'],
                    $row['plugin_requires'],
                    $row['plugin_tested'],
                    $row['plugin_requires_php'],
                    $row['plugin_description'],
                    $row['plugin_version']
                );
                $stmt->execute();
                $stmt->close();

                // Resolve plugin_id.
                $stmt = $this->db->prepare(
                    "SELECT plugin_id FROM `plugin`
                     WHERE plugin_source = 'api' AND plugin_slug = ?
                     LIMIT 1"
                );
                $stmt->bind_param('s', $row['plugin_slug']);
                $stmt->execute();
                $pRow = $stmt->get_result()->fetch_row();
                $stmt->close();

                if ($pRow === null) {
                    continue;
                }

                $pluginId = (int) $pRow[0];

                // Insert plugin_version row (idempotent).
                $stmt = $this->db->prepare(
                    "INSERT INTO `plugin_version` (plugin_id, plugin_version)
                     VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE plugin_id = plugin_id"
                );
                $stmt->bind_param('is', $pluginId, $row['plugin_version']);
                $stmt->execute();
                $stmt->close();

                // Link tracking row to the resolved plugin.
                $stmt = $this->db->prepare(
                    "UPDATE `plugin_upload` SET plugin_id = ? WHERE upload_id = ?"
                );
                $stmt->bind_param('ii', $pluginId, $row['upload_id']);
                $stmt->execute();
                $stmt->close();
            }
        }

        // ── 4. Index and FK on plugin_upload.plugin_id ───────────────────────

        $this->db->query(
            "ALTER TABLE `plugin_upload`
             ADD INDEX IF NOT EXISTS `idx_upload_plugin_id` (`plugin_id`)"
        );

        // MariaDB does not support IF NOT EXISTS on ADD CONSTRAINT FOREIGN KEY;
        // guard with an information_schema check.
        $ckFk = $this->db->query(
            "SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'plugin_upload'
               AND CONSTRAINT_NAME = 'fk_upload_plugin_id'
             LIMIT 1"
        );

        if ($ckFk instanceof mysqli_result && $ckFk->num_rows === 0) {
            $this->db->query(
                "ALTER TABLE `plugin_upload`
                 ADD CONSTRAINT `fk_upload_plugin_id`
                 FOREIGN KEY (`plugin_id`) REFERENCES `plugin` (`plugin_id`)"
            );
        }
    }

    /**
     * 2.4.0 — Add runner_sort_order to the runner table.
     *
     * Controls the display order of analysis cards on the plugin detail page
     * and in the API response. Runners with sort_order = 0 (the default) are
     * shown after explicitly-ordered runners, sorted alphabetically by name.
     */
    private function migrate240(): void
    {
        $this->db->query(
            "ALTER TABLE `runner`
             ADD COLUMN IF NOT EXISTS `runner_sort_order`
                 SMALLINT UNSIGNED NOT NULL DEFAULT 0
                 AFTER `runner_is_active`"
        );
    }
}
