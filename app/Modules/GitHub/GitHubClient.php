<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\GitHub;

use JsonException;
use RuntimeException;
use SmartToolbox\Core\ApiMetricsTracker;
use SmartToolbox\Core\FileCache;
use Throwable;

final class GitHubClient
{
    private const API_HOST = 'api.github.com';

    public function __construct(
        private readonly FileCache $cache,
        private readonly string $userAgent,
        private readonly ?string $token = null,
        private readonly string $apiVersion = '2026-03-10',
        private readonly int $cacheTtl = 1800,
        private readonly int $releaseCacheTtl = 900,
        private readonly int $connectTimeout = 4,
        private readonly int $timeout = 8,
        private readonly int $maxResponseBytes = 1048576,
        private readonly ?ApiMetricsTracker $metrics = null
    ) {
    }

    /**
     * @return array{owner: string, repository: string, full_name: string}
     */
    public function parseRepository(string $value): array
    {
        $value = trim($value);
        $value = preg_replace(
            '#^https?://github\.com/#i',
            '',
            $value
        ) ?? $value;
        $value = trim($value, "/ \t\r\n");
        $value = preg_replace('/\.git$/i', '', $value) ?? $value;

        if (
            preg_match(
                '/^([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))\/([A-Za-z0-9._-]{1,100})$/',
                $value,
                $matches
            ) !== 1
        ) {
            throw new RuntimeException(
                'Repository must use owner/repository format.'
            );
        }

        return [
            'owner' => $matches[1],
            'repository' => $matches[2],
            'full_name' => $matches[1] . '/' . $matches[2],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function repository(string $value): array
    {
        $repo = $this->parseRepository($value);
        $key = 'github.repository.'
            . mb_strtolower($repo['full_name']);

        $cached = $this->cache->remember(
            $key,
            max(60, $this->cacheTtl),
            function () use ($repo): array {
                $data = $this->request(
                    '/repos/'
                    . rawurlencode($repo['owner'])
                    . '/'
                    . rawurlencode($repo['repository'])
                );

                $languages = [];
                $commit = null;

                try {
                    $languagesData = $this->request(
                        '/repos/'
                        . rawurlencode($repo['owner'])
                        . '/'
                        . rawurlencode($repo['repository'])
                        . '/languages'
                    );

                    if (is_array($languagesData)) {
                        arsort($languagesData);
                        $languages = array_slice(
                            array_keys($languagesData),
                            0,
                            5
                        );
                    }
                } catch (Throwable) {
                }

                try {
                    $commits = $this->request(
                        '/repos/'
                        . rawurlencode($repo['owner'])
                        . '/'
                        . rawurlencode($repo['repository'])
                        . '/commits?per_page=1'
                    );

                    if (is_array($commits) && is_array($commits[0] ?? null)) {
                        $first = $commits[0];
                        $commit = [
                            'sha' => (string) ($first['sha'] ?? ''),
                            'message' => trim((string) (
                                $first['commit']['message'] ?? ''
                            )),
                            'date' => (string) (
                                $first['commit']['author']['date'] ?? ''
                            ),
                            'url' => (string) ($first['html_url'] ?? ''),
                        ];
                    }
                } catch (Throwable) {
                }

                return $this->normalizeRepository(
                    $data,
                    $languages,
                    $commit
                );
            }
        );

        if (!is_array($cached)) {
            throw new RuntimeException(
                'GitHub repository cache returned invalid data.'
            );
        }

        return $cached;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestRelease(string $value): ?array
    {
        $repo = $this->parseRepository($value);
        $key = 'github.release.'
            . mb_strtolower($repo['full_name']);

        $cached = $this->cache->remember(
            $key,
            max(60, $this->releaseCacheTtl),
            function () use ($repo): array {
                try {
                    $data = $this->request(
                        '/repos/'
                        . rawurlencode($repo['owner'])
                        . '/'
                        . rawurlencode($repo['repository'])
                        . '/releases/latest'
                    );
                } catch (GitHubNotFoundException) {
                    return ['found' => false];
                }

                return [
                    'found' => true,
                    'id' => is_numeric($data['id'] ?? null)
                        ? (int) $data['id']
                        : null,
                    'tag_name' => (string) ($data['tag_name'] ?? ''),
                    'name' => trim((string) ($data['name'] ?? '')),
                    'body' => trim((string) ($data['body'] ?? '')),
                    'draft' => (bool) ($data['draft'] ?? false),
                    'prerelease' => (bool) ($data['prerelease'] ?? false),
                    'published_at' => (string) ($data['published_at'] ?? ''),
                    'url' => (string) ($data['html_url'] ?? ''),
                    'author' => (string) ($data['author']['login'] ?? ''),
                    'repository' => $repo['full_name'],
                ];
            }
        );

        if (!is_array($cached) || ($cached['found'] ?? false) !== true) {
            return null;
        }

        return $cached;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function issues(
        string $value,
        int $limit = 5
    ): array {
        $repo = $this->parseRepository($value);
        $limit = max(1, min(20, $limit));
        $key = 'github.issues.'
            . mb_strtolower($repo['full_name'])
            . '.'
            . $limit;

        $cached = $this->cache->remember(
            $key,
            max(60, min($this->cacheTtl, 900)),
            function () use ($repo, $limit): array {
                $data = $this->request(
                    '/repos/'
                    . rawurlencode($repo['owner'])
                    . '/'
                    . rawurlencode($repo['repository'])
                    . '/issues?state=open&sort=updated&direction=desc&per_page='
                    . $limit
                );

                if (!is_array($data)) {
                    return [];
                }

                $result = [];

                foreach ($data as $item) {
                    if (!is_array($item) || isset($item['pull_request'])) {
                        continue;
                    }

                    $labels = [];
                    foreach (($item['labels'] ?? []) as $label) {
                        if (is_array($label) && is_string($label['name'] ?? null)) {
                            $labels[] = $label['name'];
                        }
                    }

                    $result[] = [
                        'number' => (int) ($item['number'] ?? 0),
                        'title' => trim((string) ($item['title'] ?? '')),
                        'url' => (string) ($item['html_url'] ?? ''),
                        'user' => (string) ($item['user']['login'] ?? ''),
                        'comments' => (int) ($item['comments'] ?? 0),
                        'updated_at' => (string) ($item['updated_at'] ?? ''),
                        'labels' => $labels,
                    ];
                }

                return $result;
            }
        );

        return is_array($cached)
            ? array_values($cached)
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $path): array
    {
        if (!str_starts_with($path, '/')) {
            throw new RuntimeException('GitHub API path is invalid.');
        }

        $url = 'https://' . self::API_HOST . $path;
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Could not initialize GitHub request.');
        }

        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: ' . $this->apiVersion,
        ];

        $token = trim((string) $this->token);
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $body = '';
        $tooLarge = false;
        $startedAt = hrtime(true);
        $statusCode = null;
        $success = false;
        $errorCode = null;

        try {
            $options = [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => max(1, $this->connectTimeout),
                CURLOPT_TIMEOUT => max($this->connectTimeout, $this->timeout),
                CURLOPT_NOSIGNAL => true,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => function (
                    mixed $curl,
                    string $chunk
                ) use (&$body, &$tooLarge): int {
                    if (
                        strlen($body) + strlen($chunk)
                        > max(4096, $this->maxResponseBytes)
                    ) {
                        $tooLarge = true;
                        return 0;
                    }

                    $body .= $chunk;
                    return strlen($chunk);
                },
            ];

            if (
                defined('CURLOPT_PROTOCOLS')
                && defined('CURLPROTO_HTTPS')
            ) {
                $options[CURLOPT_PROTOCOLS] =
                    CURLPROTO_HTTPS;
            }

            curl_setopt_array(
                $handle,
                $options
            );

            $executed = curl_exec($handle);
            $curlError = curl_error($handle);
            $curlNumber = curl_errno($handle);
            $statusCode = (int) curl_getinfo(
                $handle,
                CURLINFO_HTTP_CODE
            );

            if ($tooLarge) {
                $errorCode = 'response_too_large';
                throw new RuntimeException(
                    'GitHub response exceeded the configured size limit.'
                );
            }

            if ($executed === false) {
                $errorCode = 'curl_' . $curlNumber;
                throw new RuntimeException(
                    'GitHub connection failed: ' . $curlError
                );
            }

            if ($statusCode === 404) {
                $errorCode = 'github_404';
                throw new GitHubNotFoundException(
                    'GitHub resource was not found.'
                );
            }

            if ($statusCode === 403 || $statusCode === 429) {
                $errorCode = 'github_rate_limit';
                throw new RuntimeException(
                    'GitHub rate limit was reached. Try again after the cache refreshes.'
                );
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                $errorCode = 'github_' . $statusCode;
                throw new RuntimeException(
                    'GitHub API returned HTTP ' . $statusCode . '.'
                );
            }

            try {
                $data = json_decode(
                    $body,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException $exception) {
                $errorCode = 'invalid_json';
                throw new RuntimeException(
                    'GitHub returned invalid JSON.',
                    previous: $exception
                );
            }

            if (!is_array($data)) {
                throw new RuntimeException(
                    'GitHub response must be a JSON object or array.'
                );
            }

            $success = true;
            return $data;
        } catch (Throwable $exception) {
            $errorCode ??= $exception::class;
            throw $exception;
        } finally {
            curl_close($handle);

            $this->metrics?->record(
                provider: 'github',
                method: 'GET',
                host: self::API_HOST,
                path: strtok($path, '?') ?: $path,
                statusCode: $statusCode,
                durationMs: max(
                    0.0,
                    (hrtime(true) - $startedAt) / 1_000_000
                ),
                responseBytes: strlen($body),
                success: $success,
                errorCode: $errorCode
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $languages
     * @param array<string, mixed>|null $commit
     * @return array<string, mixed>
     */
    private function normalizeRepository(
        array $data,
        array $languages,
        ?array $commit
    ): array {
        $owner = (string) ($data['owner']['login'] ?? '');
        $name = (string) ($data['name'] ?? '');

        return [
            'id' => is_numeric($data['id'] ?? null)
                ? (int) $data['id']
                : null,
            'full_name' => (string) (
                $data['full_name'] ?? ($owner . '/' . $name)
            ),
            'description' => trim((string) ($data['description'] ?? '')),
            'url' => (string) ($data['html_url'] ?? ''),
            'homepage' => (string) ($data['homepage'] ?? ''),
            'stars' => (int) ($data['stargazers_count'] ?? 0),
            'forks' => (int) ($data['forks_count'] ?? 0),
            'open_issues' => (int) ($data['open_issues_count'] ?? 0),
            'watchers' => (int) ($data['subscribers_count'] ?? 0),
            'default_branch' => (string) ($data['default_branch'] ?? ''),
            'license' => (string) ($data['license']['spdx_id'] ?? ''),
            'language' => (string) ($data['language'] ?? ''),
            'languages' => $languages,
            'topics' => is_array($data['topics'] ?? null)
                ? array_slice($data['topics'], 0, 10)
                : [],
            'archived' => (bool) ($data['archived'] ?? false),
            'fork' => (bool) ($data['fork'] ?? false),
            'created_at' => (string) ($data['created_at'] ?? ''),
            'updated_at' => (string) ($data['updated_at'] ?? ''),
            'pushed_at' => (string) ($data['pushed_at'] ?? ''),
            'owner_avatar' => (string) ($data['owner']['avatar_url'] ?? ''),
            'commit' => $commit,
        ];
    }
}

final class GitHubNotFoundException extends RuntimeException
{
}
