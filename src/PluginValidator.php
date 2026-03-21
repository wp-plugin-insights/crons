<?php

declare(strict_types=1);

namespace PluginInsight;

use mysqli;
use RuntimeException;
use Throwable;

/**
 * Downloads, extracts, and validates WordPress plugin ZIP files.
 *
 * For each pending plugin_version record (plugin_version_tested IS NULL):
 *   1. Downloads the ZIP to $zipDir.
 *   2. Delegates extraction and parsing to ZipExtractor.
 *   3. Updates plugin_version_path and plugin_version_tested in the database.
 *   4. Publishes a JSON message via RabbitMqPublisher (if one is provided).
 *   5. Deletes the ZIP file.
 *
 * On failure the database row is left untouched so the next run retries.
 * If $publisher is null the version is still marked as tested; publishing
 * can be triggered later via the retry section of validate-plugins.php.
 */
class PluginValidator
{
    /** Maximum seconds to wait when downloading a plugin ZIP. */
    private const DOWNLOAD_TIMEOUT = 60;

    /** User-Agent header sent with every download request. */
    private const USER_AGENT = 'PluginInsight/1.0 (+https://plugininsight.com)';

    /**
     * @param mysqli $db         Active database connection.
     * @param string $zipDir     Directory where ZIPs are saved during download.
     * @param string $extractDir Root directory for plugin extraction.
     */
    public function __construct(
        private readonly mysqli $db,
        private readonly string $zipDir,
        private readonly string $extractDir
    ) {
        foreach ([$this->zipDir, $this->extractDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns up to $limit plugin versions that have a ZIP URL but have not been tested.
     *
     * Results are ordered by active installs descending so that popular plugins
     * are validated first. Within the same plugin, the highest version string
     * sorts first (lexicographic DESC is sufficient for semver comparisons at
     * this level of granularity).
     *
     * @param  int $limit Maximum number of rows to return.
     *
     * @return list<array{plugin_id: int, plugin_slug: string, plugin_version: string, plugin_version_zip: string}>
     */
    public function getPendingBatch(int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT pv.plugin_id, p.plugin_slug, pv.plugin_version, pv.plugin_version_zip
             FROM plugin_version pv
             JOIN plugin p ON p.plugin_id = pv.plugin_id
             WHERE pv.plugin_version_tested IS NULL
               AND pv.plugin_version_zip    IS NOT NULL
             ORDER BY p.plugin_installs DESC, pv.plugin_version DESC
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
     * Processes one plugin version: download → extract → persist → publish.
     *
     * The database is updated (plugin_version_tested = NOW()) before publishing.
     * If $publisher is null or the publish call fails, the version remains marked
     * as tested and the error is re-thrown so the entry-point can log it.
     *
     * @param array{plugin_id: int, plugin_slug: string, plugin_version: string, plugin_version_zip: string} $row
     * @param RabbitMqPublisher|null $publisher Optional publisher; omit to skip RabbitMQ.
     *
     * @throws RuntimeException On download, extraction, or publish failure.
     */
    public function process(array $row, ?RabbitMqPublisher $publisher): void
    {
        $slug     = $row['plugin_slug'];
        $version  = $row['plugin_version'];
        $zipUrl   = $row['plugin_version_zip'];
        $pluginId = (int) $row['plugin_id'];

        $safeSlug    = preg_replace('/[^a-z0-9._-]/i', '_', $slug);
        $safeVersion = preg_replace('/[^a-z0-9._-]/i', '_', $version);

        $zipPath    = $this->zipDir . '/' . $safeSlug . '-' . $safeVersion . '.zip';
        $extractDir = $this->extractDir . '/' . $safeSlug . '/' . $safeVersion;

        // Download — mark the row as tested on permanent failure so the queue advances
        try {
            $this->downloadZip($zipUrl, $zipPath);
        } catch (RuntimeException $e) {
            // Record the attempt so this version is not retried indefinitely.
            // plugin_version_path remains NULL to indicate the download failed.
            $this->markTested($pluginId, $version);
            throw $e;
        }

        // Extract and parse (skip extraction if already done in a previous partial run)
        $extractor = new ZipExtractor();
        try {
            if (!is_dir($extractDir)) {
                $extractor->extractAndParse($zipPath, $extractDir);
            }
        } catch (RuntimeException $e) {
            @unlink($zipPath);
            $this->markTested($pluginId, $version);
            throw $e;
        }

        // ZIP no longer needed
        @unlink($zipPath);

        // Persist
        $this->updateVersion($pluginId, $version, $extractDir);

        // Publish (optional — skip silently if no publisher is available)
        if ($publisher === null) {
            return;
        }

        try {
            $publisher->publish([
                'plugin'  => $slug,
                'source'  => 'wordpress.org',
                'version' => $version,
                'src'     => $extractDir,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'RabbitMQ publish failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Downloads a URL to a local file path via cURL.
     *
     * @param string $url  Remote URL to download.
     * @param string $dest Absolute local path to write to.
     *
     * @throws RuntimeException On file-open, network, or HTTP error.
     */
    private function downloadZip(string $url, string $dest): void
    {
        $fp = fopen($dest, 'wb');
        if ($fp === false) {
            throw new RuntimeException("Cannot open for writing: {$dest}");
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fp);
            throw new RuntimeException("curl_init failed for URL: {$url}");
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => self::DOWNLOAD_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_FAILONERROR    => true,
        ]);

        $ok    = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $errno !== 0) {
            @unlink($dest);
            throw new RuntimeException("Download failed ({$errno}): {$error} [{$url}]");
        }
    }

    /**
     * Marks a plugin version as tested and stores its extracted path.
     *
     * @param int    $pluginId  Plugin primary key.
     * @param string $version   Version string (e.g. "2.7.3").
     * @param string $path      Absolute path to the extracted directory.
     */
    private function updateVersion(int $pluginId, string $version, string $path): void
    {
        $stmt = $this->db->prepare(
            'UPDATE plugin_version
             SET plugin_version_path   = ?,
                 plugin_version_tested = NOW()
             WHERE plugin_id = ? AND plugin_version = ?'
        );
        $stmt->bind_param('sis', $path, $pluginId, $version);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Stamps plugin_version_tested = NOW() without setting a path.
     *
     * Called on permanent failures (e.g. 404, malformed URL) so the version is
     * not picked up again by getPendingBatch(). plugin_version_path remains NULL,
     * which downstream code treats as "attempted but unavailable".
     *
     * @param int    $pluginId Plugin primary key.
     * @param string $version  Version string.
     */
    private function markTested(int $pluginId, string $version): void
    {
        $stmt = $this->db->prepare(
            'UPDATE plugin_version
             SET plugin_version_tested = NOW()
             WHERE plugin_id = ? AND plugin_version = ?
               AND plugin_version_tested IS NULL'
        );

        if ($stmt === false) {
            return;
        }

        $stmt->bind_param('is', $pluginId, $version);
        $stmt->execute();
        $stmt->close();
    }
}
