<?php

declare(strict_types=1);

namespace PluginInsight;

use mysqli;
use RuntimeException;

/**
 * Fetches WordPress core release versions from the WordPress.org version-check API
 * and stores one canonical record per major.minor branch in the `site_setting` table.
 *
 * Stored setting key : wp_versions
 * Stored setting value: JSON-encoded list<array{version:string, php_min:string, mysql_min:string}>
 *                       sorted newest first; element [0] is the overall latest release.
 */
class WpVersionFetcher
{
    /** WordPress.org version-check API endpoint. */
    private const API_URL = 'https://api.wordpress.org/core/version-check/1.7/';

    /** Maximum number of seconds to wait for the HTTP response. */
    private const TIMEOUT = 15;

    /** User-Agent header sent with every HTTP request. */
    private const USER_AGENT = 'PluginInsight/1.0 (+https://plugininsight.com)';

    /** Key under which the version list is stored in `site_setting`. */
    private const SETTING_KEY = 'wp_versions';

    /**
     * @param mysqli $db Active database connection.
     */
    public function __construct(private readonly mysqli $db)
    {
    }

    /**
     * Fetches the WP version list, deduplicates by major.minor branch (keeping
     * the highest patch), and persists the result as JSON in site_setting.
     *
     * Prints a one-line summary to STDOUT on success.
     *
     * @throws RuntimeException On HTTP failure, network error, or invalid JSON.
     */
    public function run(): void
    {
        $offers   = $this->fetch();
        $versions = $this->deduplicate($offers);
        $this->persist($versions);

        $count  = count($versions);
        $latest = $versions[0]['version'] ?? '?';
        echo "Stored {$count} WP version branch(es). Latest: {$latest}\n";
    }

    /**
     * Fetches the raw offers array from the WordPress.org API.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException On cURL error, non-200 HTTP status, or invalid JSON.
     */
    private function fetch(): array
    {
        $ch = curl_init(self::API_URL);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '') {
            throw new RuntimeException("cURL error: {$err}");
        }

        if ($code !== 200) {
            throw new RuntimeException("HTTP {$code} from WordPress.org version API");
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON from WordPress.org version API');
        }

        $offers = $data['offers'] ?? [];

        return is_array($offers) ? array_values($offers) : [];
    }

    /**
     * Returns one entry per major.minor branch, keeping the highest patch version.
     *
     * Entries are sorted newest-first so that index 0 is always the current
     * latest stable release.
     *
     * @param  list<array<string, mixed>> $offers Raw offers from the API.
     *
     * @return list<array{version: string, php_min: string, mysql_min: string}>
     */
    private function deduplicate(array $offers): array
    {
        /** @var array<string, array{version: string, php_min: string, mysql_min: string}> */
        $byMinor = [];

        foreach ($offers as $offer) {
            $version = (string) ($offer['current'] ?? $offer['version'] ?? '');
            if ($version === '' || !preg_match('/^\d+\.\d+/', $version)) {
                continue;
            }

            $parts      = explode('.', $version);
            $majorMinor = $parts[0] . '.' . ($parts[1] ?? '0');

            $isNewer = !isset($byMinor[$majorMinor])
                || version_compare($version, $byMinor[$majorMinor]['version'], '>');
            if ($isNewer) {
                $byMinor[$majorMinor] = [
                    'version'   => $version,
                    'php_min'   => (string) ($offer['php_version']   ?? ''),
                    'mysql_min' => (string) ($offer['mysql_version'] ?? ''),
                ];
            }
        }

        // Sort newest first
        uasort(
            $byMinor,
            static fn (array $a, array $b): int => version_compare($b['version'], $a['version'])
        );

        return array_values($byMinor);
    }

    /**
     * Persists the version list as JSON in the site_setting table.
     *
     * Uses INSERT … ON DUPLICATE KEY UPDATE so the row is created on first run
     * and updated on every subsequent run.
     *
     * @param list<array{version: string, php_min: string, mysql_min: string}> $versions
     *
     * @throws RuntimeException If json_encode fails (should not happen in practice).
     */
    private function persist(array $versions): void
    {
        $json = json_encode($versions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('json_encode failed');
        }

        $key  = self::SETTING_KEY;
        $stmt = $this->db->prepare(
            'INSERT INTO site_setting (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->bind_param('ss', $key, $json);
        $stmt->execute();
        $stmt->close();
    }
}
