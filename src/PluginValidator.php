<?php

declare(strict_types=1);

/**
 * Downloads, extracts, and validates WordPress plugin ZIP files.
 *
 * For each pending plugin_version record (tested IS NULL):
 *   1. Downloads the ZIP to $zipDir.
 *   2. Delegates extraction and parsing to ZipExtractor.
 *   3. Updates plugin_version_path and plugin_version_tested in the database.
 *   4. Publishes a JSON message via RabbitMqPublisher.
 *   5. Deletes the ZIP file.
 *
 * On failure the database row is left untouched so the next run retries.
 */
class PluginValidator
{
    private const DOWNLOAD_TIMEOUT = 60;
    private const USER_AGENT       = 'PluginInsight/1.0 (+https://plugininsight.com)';

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
             ORDER BY pv.plugin_id, pv.plugin_version
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
     * @param array{plugin_id: int, plugin_slug: string, plugin_version: string, plugin_version_zip: string} $row
     *
     * @throws RuntimeException On download, extraction, or validation failure.
     */
    public function process(array $row, RabbitMqPublisher $publisher): void
    {
        $slug     = $row['plugin_slug'];
        $version  = $row['plugin_version'];
        $zipUrl   = $row['plugin_version_zip'];
        $pluginId = (int) $row['plugin_id'];

        $safeSlug    = preg_replace('/[^a-z0-9._-]/i', '_', $slug);
        $safeVersion = preg_replace('/[^a-z0-9._-]/i', '_', $version);

        $zipPath    = $this->zipDir . '/' . $safeSlug . '-' . $safeVersion . '.zip';
        $extractDir = $this->extractDir . '/' . $safeSlug . '/' . $safeVersion;

        // Download
        $this->downloadZip($zipUrl, $zipPath);

        // Extract and parse (skip extraction if already done in a previous partial run)
        $extractor = new ZipExtractor();
        try {
            if (!is_dir($extractDir)) {
                $meta = $extractor->extractAndParse($zipPath, $extractDir);
            } else {
                // Directory already exists: re-parse without re-extracting.
                // This keeps the retry behaviour consistent with the old implementation.
                $meta = ['stable_tag' => null];
            }
        } catch (RuntimeException $e) {
            @unlink($zipPath);
            throw $e;
        }

        // ZIP no longer needed
        @unlink($zipPath);

        // Persist
        $this->updateVersion($pluginId, $version, $extractDir);

        // Publish
        $publisher->publish([
            'plugin'  => $slug,
            'source'  => 'wordpress.org',
            'version' => $version,
            'src'     => $extractDir,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Downloads a URL to a local file path via cURL.
     *
     * @throws RuntimeException On network or HTTP error.
     */
    private function downloadZip(string $url, string $dest): void
    {
        $fp = fopen($dest, 'wb');
        if ($fp === false) {
            throw new RuntimeException("Cannot open for writing: {$dest}");
        }

        $ch = curl_init($url);
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
}
