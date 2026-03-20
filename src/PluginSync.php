<?php

declare(strict_types=1);

/**
 * Synchronizes plugin data from the WordPress.org API into the local database.
 *
 * Uses INSERT … ON DUPLICATE KEY UPDATE so each plugin requires a single query.
 * affected_rows returns 1 (inserted), 2 (updated), or 0 (unchanged).
 */
class PluginSync
{
    public function __construct(private readonly mysqli $db)
    {
    }

    /**
     * Inserts or updates a single plugin record with all available API metadata.
     *
     * @param array<string, mixed> $plugin Raw plugin array from the WordPress API.
     *
     * @return 'inserted'|'updated'|'unchanged'
     */
    public function upsert(array $plugin): string
    {
        $slug                    = (string) ($plugin['slug']                     ?? '');
        $version                 = (string) ($plugin['version']                  ?? '');
        $installs                = (int)    ($plugin['active_installs']          ?? 0);
        $zip                     = (string) ($plugin['download_link']            ?? '');
        $name                    = (string) ($plugin['name']                     ?? '');
        $requires                = (string) ($plugin['requires']                 ?? '');
        $tested                  = (string) ($plugin['tested']                   ?? '');
        $requiresPhp             = (string) ($plugin['requires_php']             ?? '');
        $requiresPlugins         = json_encode($plugin['requires_plugins']       ?? []);
        $rating                  = (int)    ($plugin['rating']                   ?? 0);
        $numRatings              = (int)    ($plugin['num_ratings']              ?? 0);
        $supportThreads          = (int)    ($plugin['support_threads']          ?? 0);
        $supportThreadsResolved  = (int)    ($plugin['support_threads_resolved'] ?? 0);
        $downloaded              = (int)    ($plugin['downloaded']               ?? 0);
        $lastUpdated             = $this->parseDateTime((string) ($plugin['last_updated'] ?? ''));
        $added                   = (string) ($plugin['added']                    ?? '');

        if ($slug === '') {
            return 'unchanged';
        }

        $stmt = $this->db->prepare(
            'INSERT INTO plugin (
                plugin_slug, plugin_version, plugin_installs, plugin_zip,
                plugin_name, plugin_requires, plugin_tested, plugin_requires_php,
                plugin_requires_plugins, plugin_rating, plugin_num_ratings,
                plugin_support_threads, plugin_support_threads_resolved,
                plugin_downloaded, plugin_last_updated, plugin_added
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
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
                plugin_added                    = VALUES(plugin_added)'
        );

        $stmt->bind_param(
            'ssissssssiiiiiss',
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
            $added
        );

        $stmt->execute();
        $affected = $this->db->affected_rows;
        $stmt->close();

        return match ($affected) {
            1       => 'inserted',
            2       => 'updated',
            default => 'unchanged',
        };
    }

    /**
     * Converts the WordPress API last_updated string (e.g. "2026-03-20 2:51pm GMT")
     * into a MySQL-compatible datetime string, or returns null on failure.
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
