<?php

declare(strict_types=1);

namespace PluginInsight;

use mysqli;
use RuntimeException;

/**
 * Fetches WordPress locale (language) metadata from the WordPress.org
 * translations API and upserts one row per language code into the
 * `wp_locale` table.
 *
 * API endpoint : https://api.wordpress.org/translations/core/1.0/
 * Stored fields: language, english_name, native_name
 *
 * Designed to run once per week via a systemd timer.
 */
class WpLocalesFetcher
{
    /** WordPress.org translations API endpoint. */
    private const API_URL = 'https://api.wordpress.org/translations/core/1.0/';

    /** Maximum number of seconds to wait for the HTTP response. */
    private const TIMEOUT = 20;

    /** User-Agent header sent with every HTTP request. */
    private const USER_AGENT = 'PluginInsight/1.0 (+https://plugininsight.com)';

    /**
     * @param mysqli $db Active database connection.
     */
    public function __construct(private readonly mysqli $db)
    {
    }

    /**
     * Fetches the translations list and upserts locale rows into the database.
     *
     * Prints a one-line summary to STDOUT on success.
     *
     * @throws RuntimeException On HTTP failure, network error, or invalid JSON.
     */
    public function run(): void
    {
        $translations = $this->fetch();
        $count        = $this->upsert($translations);

        echo "Upserted {$count} locale(s).\n";
    }

    /**
     * Fetches the raw translations array from the WordPress.org API.
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
            throw new RuntimeException("HTTP {$code} from WordPress.org translations API");
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON from WordPress.org translations API');
        }

        $translations = $data['translations'] ?? [];

        return is_array($translations) ? array_values($translations) : [];
    }

    /**
     * Upserts locale rows into wp_locale.
     *
     * Uses INSERT … ON DUPLICATE KEY UPDATE so existing rows are kept current
     * on every subsequent run. Duplicate language codes within the API response
     * are deduplicated: the entry with the most recent `updated` timestamp wins.
     *
     * @param  list<array<string, mixed>> $translations Raw translation entries from the API.
     *
     * @return int Number of rows actually inserted or updated (0 = no changes).
     */
    private function upsert(array $translations): int
    {
        /** @var array<string, array{english_name: string, native_name: string, updated: string}> */
        $byLanguage = [];

        foreach ($translations as $t) {
            $language    = trim((string) ($t['language']     ?? ''));
            $englishName = trim((string) ($t['english_name'] ?? ''));
            $nativeName  = trim((string) ($t['native_name']  ?? ''));
            $updated     = trim((string) ($t['updated']      ?? ''));

            if ($language === '' || $englishName === '') {
                continue;
            }

            $isNewer = !isset($byLanguage[$language])
                || strcmp($updated, $byLanguage[$language]['updated']) > 0;

            if ($isNewer) {
                $byLanguage[$language] = [
                    'english_name' => $englishName,
                    'native_name'  => $nativeName,
                    'updated'      => $updated,
                ];
            }
        }

        if (empty($byLanguage)) {
            return 0;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO wp_locale (locale_language, locale_english_name, locale_native_name)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 locale_english_name = VALUES(locale_english_name),
                 locale_native_name  = VALUES(locale_native_name),
                 locale_synced_at    = CURRENT_TIMESTAMP'
        );

        $count = 0;
        foreach ($byLanguage as $language => $data) {
            $stmt->bind_param('sss', $language, $data['english_name'], $data['native_name']);
            $stmt->execute();
            // affected_rows: 1 = insert, 2 = update, 0 = no-op
            if ($this->db->affected_rows > 0) {
                $count++;
            }
        }

        $stmt->close();

        return $count;
    }
}
