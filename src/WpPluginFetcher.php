<?php

declare(strict_types=1);

/**
 * Fetches plugin data from the WordPress.org Plugins API.
 *
 * Endpoint: https://api.wordpress.org/plugins/info/1.2/
 * Browse: new (plugins ordered by date added, newest first)
 */
class WpPluginFetcher
{
    private const API_URL = 'https://api.wordpress.org/plugins/info/1.2/';
    private const TIMEOUT = 30;
    private const USER_AGENT = 'PluginInsight/1.0 (+https://plugininsight.com)';

    /**
     * Fetches one page of plugins from the WordPress.org API.
     *
     * @param int $page    Page number (1-based).
     * @param int $perPage Number of plugins per page (max 200).
     *
     * @return array{
     *     info: array{page: int, pages: int, results: int},
     *     plugins: list<array<string, mixed>>
     * }
     *
     * @throws RuntimeException On HTTP or JSON parsing failure.
     */
    public function fetchPage(int $page, int $perPage = 200): array
    {
        $url = self::API_URL . '?' . http_build_query([
            'action'  => 'query_plugins',
            'request' => [
                'browse'   => 'new',
                'fields'   => ['versions' => 1],
                'per_page' => $perPage,
                'page'     => $page,
            ],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            throw new RuntimeException("cURL error ({$errno}): {$error}");
        }

        $data = json_decode((string) $response, true);

        if (!is_array($data) || !isset($data['plugins'], $data['info'])) {
            throw new RuntimeException('Unexpected WordPress API response format.');
        }

        return $data;
    }
}
