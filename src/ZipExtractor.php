<?php

declare(strict_types=1);

/**
 * Validates, extracts, and parses WordPress plugin ZIP archives.
 *
 * Performs security checks (MIME type, path traversal, ZIP-bomb detection)
 * before extracting to a destination directory, then parses plugin metadata
 * from both readme.txt and the main plugin PHP file header.
 *
 * This class is shared between the cron pipeline (PluginValidator) and the
 * upload API endpoint (api.plugininsight.com).
 */
class ZipExtractor
{
    /** Maximum total uncompressed size of all entries combined. */
    private const MAX_EXTRACTED_BYTES = 512 * 1024 * 1024; // 512 MB

    /** Maximum number of entries in a single ZIP archive. */
    private const MAX_ZIP_ENTRIES = 5000;

    /** Maximum uncompressed-to-compressed ratio per entry (ZIP-bomb guard). */
    private const MAX_COMPRESSION_RATIO = 100;

    /** Maximum bytes read from readme.txt. */
    private const README_MAX_BYTES = 65536; // 64 KB

    /** Maximum bytes read from a PHP file when scanning for plugin headers. */
    private const PHP_HEADER_MAX_BYTES = 8192; // 8 KB

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Validates the ZIP, extracts it safely to $destDir, then parses and
     * returns plugin metadata from readme.txt and the main plugin PHP file.
     *
     * @param string $zipPath Absolute path to the ZIP file to process.
     * @param string $destDir Absolute path to the extraction destination.
     *
     * @return array{
     *     plugin_slug: string|null,
     *     plugin_name: string|null,
     *     plugin_version: string|null,
     *     plugin_author: string|null,
     *     plugin_requires: string|null,
     *     plugin_tested: string|null,
     *     plugin_requires_php: string|null,
     *     plugin_description: string|null,
     *     stable_tag: string|null,
     * }
     *
     * @throws RuntimeException On any validation, extraction, or parsing failure.
     */
    public function extractAndParse(string $zipPath, string $destDir): array
    {
        $this->validateZipMime($zipPath);
        $this->extractSafe($zipPath, $destDir);

        $readmePath = $this->findReadme($destDir);
        if ($readmePath === null) {
            throw new RuntimeException('No readme.txt found in the plugin archive.');
        }

        $this->validateTextMime($readmePath);

        $readmeMeta = $this->parseReadme($readmePath);
        $phpMeta    = $this->parseMainPluginFile($destDir);

        return [
            'plugin_slug'        => $phpMeta['plugin_slug'],
            'plugin_name'        => $phpMeta['plugin_name'] ?? $readmeMeta['plugin_name'],
            'plugin_version'     => $phpMeta['plugin_version'],
            'plugin_author'      => $phpMeta['plugin_author'],
            'plugin_requires'    => $readmeMeta['requires_at_least'],
            'plugin_tested'      => $readmeMeta['tested_up_to'],
            'plugin_requires_php' => $phpMeta['requires_php'] ?? $readmeMeta['requires_php'],
            'plugin_description' => $phpMeta['description'] ?? $readmeMeta['short_description'],
            'stable_tag'         => $readmeMeta['stable_tag'],
        ];
    }

    // -------------------------------------------------------------------------
    // MIME validation
    // -------------------------------------------------------------------------

    /**
     * Asserts that $path is a ZIP archive by inspecting its magic bytes.
     *
     * @throws RuntimeException If the MIME type is not application/zip.
     */
    private function validateZipMime(string $path): void
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($path);

        if (!in_array($mime, ['application/zip', 'application/x-zip-compressed'], true)) {
            throw new RuntimeException(
                "Invalid file type '{$mime}': only ZIP archives are accepted."
            );
        }
    }

    /**
     * Asserts that $path is a plain-text file by inspecting its magic bytes.
     *
     * @throws RuntimeException If the MIME type is not text/plain.
     */
    private function validateTextMime(string $path): void
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($path);

        if ($mime !== 'text/plain') {
            throw new RuntimeException(
                "readme.txt has unexpected MIME type '{$mime}': expected text/plain."
            );
        }
    }

    // -------------------------------------------------------------------------
    // Safe extraction
    // -------------------------------------------------------------------------

    /**
     * Extracts the ZIP to $destDir with path-traversal and ZIP-bomb protection.
     *
     * All entries are scanned before extraction. Any entry that would cause a
     * path traversal, exceeds the per-entry compression ratio, or pushes the
     * total uncompressed size over the limit causes an immediate abort.
     *
     * @throws RuntimeException On any security violation or extraction error.
     */
    private function extractSafe(string $zipPath, string $destDir): void
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Cannot open ZIP archive: {$zipPath}");
        }

        $numEntries = $zip->numFiles;

        if ($numEntries > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            throw new RuntimeException(
                "ZIP contains {$numEntries} entries, exceeding the limit of "
                . self::MAX_ZIP_ENTRIES . '.'
            );
        }

        $totalUncompressed = 0;

        for ($i = 0; $i < $numEntries; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            // Path-traversal guard: reject any entry whose name contains '..'
            if (str_contains($stat['name'], '..')) {
                $zip->close();
                throw new RuntimeException(
                    "Path traversal detected in ZIP entry: {$stat['name']}"
                );
            }

            // ZIP-bomb guard: per-entry compression ratio
            if ($stat['comp_size'] > 0) {
                $ratio = $stat['size'] / $stat['comp_size'];
                if ($ratio > self::MAX_COMPRESSION_RATIO) {
                    $zip->close();
                    throw new RuntimeException(
                        sprintf(
                            'Suspicious compression ratio (%.0f:1) in ZIP entry: %s',
                            $ratio,
                            $stat['name']
                        )
                    );
                }
            }

            // ZIP-bomb guard: cumulative uncompressed size
            $totalUncompressed += $stat['size'];
            if ($totalUncompressed > self::MAX_EXTRACTED_BYTES) {
                $zip->close();
                throw new RuntimeException(
                    'ZIP extraction would exceed the maximum allowed size of '
                    . (self::MAX_EXTRACTED_BYTES / 1024 / 1024) . ' MB.'
                );
            }
        }

        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $zip->extractTo($destDir);
        $zip->close();
    }

    // -------------------------------------------------------------------------
    // readme.txt discovery and parsing
    // -------------------------------------------------------------------------

    /**
     * Searches for readme.txt (case-insensitive) up to one level deep inside
     * the extraction directory.
     */
    private function findReadme(string $extractDir): ?string
    {
        $candidates = array_merge(
            glob($extractDir . '/readme.txt')   ?: [],
            glob($extractDir . '/README.txt')   ?: [],
            glob($extractDir . '/*/readme.txt') ?: [],
            glob($extractDir . '/*/README.txt') ?: []
        );

        return $candidates[0] ?? null;
    }

    /**
     * Reads and parses the standard WordPress readme.txt headers and short
     * description from $path.
     *
     * @return array{
     *     plugin_name: string|null,
     *     stable_tag: string|null,
     *     requires_at_least: string|null,
     *     tested_up_to: string|null,
     *     requires_php: string|null,
     *     short_description: string|null,
     * }
     */
    private function parseReadme(string $path): array
    {
        $content = $this->readLimited($path, self::README_MAX_BYTES);

        return [
            'plugin_name'       => $this->parseFirstHeading($content),
            'stable_tag'        => $this->parseReadmeHeader($content, 'Stable tag'),
            'requires_at_least' => $this->parseReadmeHeader($content, 'Requires at least'),
            'tested_up_to'      => $this->parseReadmeHeader($content, 'Tested up to'),
            'requires_php'      => $this->parseReadmeHeader($content, 'Requires PHP'),
            'short_description' => $this->parseShortDescription($content),
        ];
    }

    /**
     * Extracts the value of a "Key: value" header line from readme.txt content.
     */
    private function parseReadmeHeader(string $content, string $key): ?string
    {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*:\s*(.+)$/im', $content, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Extracts the plugin name from the "=== Plugin Name ===" title heading.
     */
    private function parseFirstHeading(string $content): ?string
    {
        if (preg_match('/^===\s*(.+?)\s*===/m', $content, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Extracts the short description from readme.txt.
     *
     * The short description is the first non-empty paragraph that appears
     * after the header key-value block and before the first == Section ==
     * heading.
     */
    private function parseShortDescription(string $content): ?string
    {
        // Strip the === title === line.
        $body = preg_replace('/^===.+===\R?/m', '', $content, 1) ?? $content;

        // Strip all "Key: value" header lines.
        $body = preg_replace('/^[\w][\w ]*:[ \t]+.+$/m', '', $body) ?? $body;

        // Split into paragraphs and return the first non-empty one that is
        // not a section heading.
        $paragraphs = preg_split('/\R{2,}/', $body) ?: [];

        foreach ($paragraphs as $para) {
            $trimmed = trim($para);
            if ($trimmed === '' || preg_match('/^==/', $trimmed)) {
                continue;
            }

            return $trimmed;
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Main plugin PHP file discovery and parsing
    // -------------------------------------------------------------------------

    /**
     * Locates and parses the main plugin PHP file header.
     *
     * The main plugin file is the PHP file (up to one level deep) that contains
     * a "Plugin Name:" header. The plugin slug is derived from the name of the
     * directory that directly contains that file (if it differs from $extractDir).
     *
     * @return array{
     *     plugin_slug: string|null,
     *     plugin_name: string|null,
     *     plugin_version: string|null,
     *     plugin_author: string|null,
     *     requires_php: string|null,
     *     description: string|null,
     * }
     */
    private function parseMainPluginFile(string $extractDir): array
    {
        $empty = [
            'plugin_slug'    => null,
            'plugin_name'    => null,
            'plugin_version' => null,
            'plugin_author'  => null,
            'requires_php'   => null,
            'description'    => null,
        ];

        $phpFile = $this->findMainPluginFile($extractDir);
        if ($phpFile === null) {
            return $empty;
        }

        $content = $this->readLimited($phpFile, self::PHP_HEADER_MAX_BYTES);

        // Derive the slug from the directory that directly holds the PHP file,
        // but only when that directory is a subdirectory of $extractDir (i.e.
        // the ZIP extracted into a plugin-slug/ folder, as is standard).
        $parentDir = dirname($phpFile);
        $slug      = ($parentDir !== $extractDir) ? basename($parentDir) : null;

        return [
            'plugin_slug'    => $slug,
            'plugin_name'    => $this->parsePhpHeader($content, 'Plugin Name'),
            'plugin_version' => $this->parsePhpHeader($content, 'Version'),
            'plugin_author'  => $this->parsePhpHeader($content, 'Author'),
            'requires_php'   => $this->parsePhpHeader($content, 'Requires PHP'),
            'description'    => $this->parsePhpHeader($content, 'Description'),
        ];
    }

    /**
     * Scans PHP files up to one level deep for the one containing "Plugin Name:".
     */
    private function findMainPluginFile(string $extractDir): ?string
    {
        $candidates = array_merge(
            glob($extractDir . '/*.php')   ?: [],
            glob($extractDir . '/*/*.php') ?: []
        );

        foreach ($candidates as $file) {
            $content = $this->readLimited($file, self::PHP_HEADER_MAX_BYTES);
            if ($this->parsePhpHeader($content, 'Plugin Name') !== null) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Extracts the value of a standard WordPress plugin file header field
     * (e.g. "Plugin Name: Foo", "Version: 1.0.0").
     */
    private function parsePhpHeader(string $content, string $field): ?string
    {
        if (preg_match('/' . preg_quote($field, '/') . '\s*:\s*(.+)/i', $content, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Reads up to $maxBytes bytes from a file.
     *
     * @throws RuntimeException If the file cannot be read.
     */
    private function readLimited(string $path, int $maxBytes): string
    {
        $content = file_get_contents($path, false, null, 0, $maxBytes);
        if ($content === false) {
            throw new RuntimeException("Cannot read file: {$path}");
        }

        return $content;
    }
}
