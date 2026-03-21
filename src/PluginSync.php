<?php

declare(strict_types=1);

namespace PluginInsight;

use mysqli;

/**
 * Synchronizes plugin data from the WordPress.org API into the local database.
 *
 * Uses INSERT … ON DUPLICATE KEY UPDATE so each plugin requires a single query.
 * The LAST_INSERT_ID(plugin_id) trick ensures insert_id is always populated,
 * regardless of whether the row was inserted or updated.
 *
 * affected_rows returns 1 (inserted), 2 (updated), or 0 (unchanged).
 */
class PluginSync
{
    /**
     * @param mysqli $db Active database connection.
     */
    public function __construct(private readonly mysqli $db)
    {
    }

    /**
     * Inserts or updates a single plugin record with all available API metadata.
     *
     * Returns 'unchanged' with plugin_id 0 if the slug is missing from $plugin.
     *
     * @param  array<string, mixed> $plugin Raw plugin array from the WordPress API.
     *
     * @return array{result: 'inserted'|'updated'|'unchanged', plugin_id: int}
     */
    public function upsert(array $plugin): array
    {
        $slug                 = trim((string) ($plugin['slug']                     ?? ''));
        $version              = trim((string) ($plugin['version']                  ?? ''));
        $installs             = (int)    ($plugin['active_installs']          ?? 0);
        $zip                  = (string) ($plugin['download_link']            ?? '');
        $name                 = (string) ($plugin['name']                     ?? '');
        $requires             = (string) ($plugin['requires']                 ?? '');
        $tested               = (string) ($plugin['tested']                   ?? '');
        $requiresPhp          = (string) ($plugin['requires_php']             ?? '');
        $requiresPlugins      = json_encode($plugin['requires_plugins']       ?? []);
        $rating               = (int)    ($plugin['rating']                   ?? 0);
        $numRatings           = (int)    ($plugin['num_ratings']              ?? 0);
        $supportThreads       = (int)    ($plugin['support_threads']          ?? 0);
        $supportThreadsResolved = (int)  ($plugin['support_threads_resolved'] ?? 0);
        $downloaded           = (int)    ($plugin['downloaded']               ?? 0);
        $lastUpdated          = $this->parseDateTime((string) ($plugin['last_updated']    ?? ''));
        $added                = (string) ($plugin['added']                    ?? '');
        $source               = 'wordpress.org';
        $author               = (string) ($plugin['author']                   ?? '');
        $authorProfile        = (string) ($plugin['author_profile']           ?? '');
        $homepage             = (string) ($plugin['homepage']                 ?? '');
        $shortDescription     = (string) ($plugin['short_description']        ?? '');
        $icons                = json_encode($plugin['icons']                  ?? []);

        if ($slug === '') {
            return ['result' => 'unchanged', 'plugin_id' => 0];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO plugin (
                plugin_slug, plugin_version, plugin_installs, plugin_zip,
                plugin_name, plugin_requires, plugin_tested, plugin_requires_php,
                plugin_requires_plugins, plugin_rating, plugin_num_ratings,
                plugin_support_threads, plugin_support_threads_resolved,
                plugin_downloaded, plugin_last_updated, plugin_added,
                plugin_source, plugin_author, plugin_author_profile,
                plugin_homepage, plugin_short_description, plugin_icons
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                plugin_id                       = LAST_INSERT_ID(plugin_id),
                plugin_version                  = VALUES(plugin_version),
                plugin_installs                 = VALUES(plugin_installs),
                plugin_zip                      = VALUES(plugin_zip),
                plugin_name                     = VALUES(plugin_name),
                plugin_requires                 = VALUES(plugin_requires),
                plugin_tested                   = VALUES(plugin_tested),
                plugin_requires_php             = VALUES(plugin_requires_php),
                plugin_requires_plugins         = VALUES(plugin_requires_plugins),
                plugin_rating                   = VALUES(plugin_rating),
                plugin_num_ratings              = VALUES(plugin_num_ratings),
                plugin_support_threads          = VALUES(plugin_support_threads),
                plugin_support_threads_resolved = VALUES(plugin_support_threads_resolved),
                plugin_downloaded               = VALUES(plugin_downloaded),
                plugin_last_updated             = VALUES(plugin_last_updated),
                plugin_added                    = VALUES(plugin_added),
                plugin_author                   = VALUES(plugin_author),
                plugin_author_profile           = VALUES(plugin_author_profile),
                plugin_homepage                 = VALUES(plugin_homepage),
                plugin_short_description        = VALUES(plugin_short_description),
                plugin_icons                    = VALUES(plugin_icons)'
        );

        $stmt->bind_param(
            'ssissssssiiiiissssssss',
            $slug,
            $version,
            $installs,
            $zip,
            $name,
            $requires,
            $tested,
            $requiresPhp,
            $requiresPlugins,
            $rating,
            $numRatings,
            $supportThreads,
            $supportThreadsResolved,
            $downloaded,
            $lastUpdated,
            $added,
            $source,
            $author,
            $authorProfile,
            $homepage,
            $shortDescription,
            $icons
        );

        $stmt->execute();
        $affected  = $this->db->affected_rows;
        $pluginId  = (int) $this->db->insert_id;
        $stmt->close();

        $result = match ($affected) {
            1       => 'inserted',
            2       => 'updated',
            default => 'unchanged',
        };

        return ['result' => $result, 'plugin_id' => $pluginId];
    }

    /**
     * Inserts missing version records for a plugin.
     *
     * Skips 'trunk' since it is a floating pointer, not a discrete release.
     * Uses INSERT IGNORE because ZIP URLs for released versions are immutable.
     * The plugin_version_tested column defaults to NULL and is updated externally
     * when a test run completes.
     *
     * @param int                  $pluginId Plugin primary key.
     * @param array<string, mixed> $versions Associative array of version => zip_url.
     */
    public function syncVersions(int $pluginId, array $versions): void
    {
        foreach ($versions as $ver => $zip) {
            if ($ver === 'trunk') {
                continue;
            }

            $ver  = trim((string) $ver);
            $zip  = trim((string) $zip);

            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO plugin_version (plugin_id, plugin_version, plugin_version_zip)
                 VALUES (?, ?, ?)'
            );
            $stmt->bind_param('iss', $pluginId, $ver, $zip);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Converts the WordPress API last_updated string (e.g. "2026-03-20 2:51pm GMT")
     * into a MySQL-compatible datetime string, or returns null on failure.
     *
     * @param  string $value Raw date string from the WordPress.org API.
     *
     * @return string|null MySQL datetime string ("Y-m-d H:i:s"), or null if unparseable.
     */
    private function parseDateTime(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);

        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }
}
