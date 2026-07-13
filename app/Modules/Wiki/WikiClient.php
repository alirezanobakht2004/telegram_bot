<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Wiki;

use RuntimeException;
use SmartToolbox\Core\FileCache;
use SmartToolbox\Core\HttpClient;
use Throwable;

final class WikiClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly FileCache $cache,
        private readonly int $searchCacheTtl = 21600,
        private readonly int $randomCacheTtl = 300,
        private readonly int $todayCacheTtl = 21600
    ) {
    }

    /**
     * @return list<array{
     *     title: string,
     *     extract: string,
     *     url: string,
     *     thumbnail: ?string,
     *     language: string
     * }>
     */
    public function search(
        string $query,
        string $language,
        int $limit = 5
    ): array {
        $query = trim($query);
        $language = $this->language($language);
        $limit = max(1, min(10, $limit));

        if ($query === '') {
            return [];
        }

        $key = 'wiki.search.'
            . $language
            . '.'
            . hash('sha256', mb_strtolower($query))
            . '.'
            . $limit;

        $result = $this->cache->remember(
            $key,
            max(60, $this->searchCacheTtl),
            function () use (
                $query,
                $language,
                $limit
            ): array {
                return $this->searchRemote(
                    $query,
                    $language,
                    $limit
                );
            }
        );

        return is_array($result)
            ? array_values($result)
            : [];
    }

    /**
     * @return array{
     *     title: string,
     *     extract: string,
     *     url: string,
     *     thumbnail: ?string,
     *     language: string
     * }|null
     */
    public function first(
        string $query,
        string $language
    ): ?array {
        $results = $this->search(
            $query,
            $language,
            3
        );

        if ($results !== []) {
            return $results[0];
        }

        if ($language !== 'en') {
            $fallback = $this->search(
                $query,
                'en',
                3
            );

            return $fallback[0] ?? null;
        }

        return null;
    }

    /**
     * @return array{
     *     title: string,
     *     extract: string,
     *     url: string,
     *     thumbnail: ?string,
     *     language: string
     * }
     */
    public function random(string $language): array
    {
        $language = $this->language($language);
        $key = 'wiki.random.'
            . $language
            . '.'
            . intdiv(time(), max(60, $this->randomCacheTtl));

        $result = $this->cache->remember(
            $key,
            max(60, $this->randomCacheTtl),
            function () use ($language): array {
                $url = $this->actionApi($language)
                    . '?'
                    . http_build_query(
                        [
                            'action' => 'query',
                            'generator' => 'random',
                            'grnnamespace' => 0,
                            'grnlimit' => 1,
                            'prop' => 'extracts|pageimages|info',
                            'exintro' => 1,
                            'explaintext' => 1,
                            'exsentences' => 5,
                            'piprop' => 'thumbnail|original',
                            'pithumbsize' => 600,
                            'inprop' => 'url',
                            'format' => 'json',
                            'formatversion' => 2,
                        ],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    );

                $data = $this->http
                    ->get($url)
                    ->requireSuccess()
                    ->jsonArray();

                $pages = $data['query']['pages'] ?? null;

                if (!is_array($pages) || !is_array($pages[0] ?? null)) {
                    throw new RuntimeException(
                        'Wikipedia returned no random article.'
                    );
                }

                return $this->normalizePage(
                    $pages[0],
                    $language
                );
            }
        );

        if (!is_array($result)) {
            throw new RuntimeException(
                'Random Wikipedia cache returned invalid data.'
            );
        }

        return $result;
    }

    /**
     * @return array{
     *     selected: list<array{year: int|string, text: string, pages: list<array<string, mixed>>}>,
     *     births: list<array{year: int|string, text: string, pages: list<array<string, mixed>>}>,
     *     deaths: list<array{year: int|string, text: string, pages: list<array<string, mixed>>}>,
     *     holidays: list<array{year: int|string, text: string, pages: list<array<string, mixed>>}>,
     *     language: string,
     *     month: int,
     *     day: int
     * }
     */
    public function onThisDay(
        string $language,
        int $month,
        int $day
    ): array {
        $language = $this->language($language);

        if (!checkdate($month, $day, 2000)) {
            throw new RuntimeException('Month or day is invalid.');
        }

        $key = sprintf(
            'wiki.onthisday.%s.%02d.%02d',
            $language,
            $month,
            $day
        );

        $result = $this->cache->remember(
            $key,
            max(300, $this->todayCacheTtl),
            function () use (
                $language,
                $month,
                $day
            ): array {
                $url = sprintf(
                    'https://%s.wikipedia.org/api/rest_v1/feed/onthisday/all/%02d/%02d',
                    $language,
                    $month,
                    $day
                );

                try {
                    $data = $this->http
                        ->get($url, [
                            'Accept' => 'application/json',
                        ])
                        ->requireSuccess()
                        ->jsonArray();
                } catch (Throwable $exception) {
                    if ($language === 'en') {
                        throw $exception;
                    }

                    return $this->onThisDayRemote(
                        'en',
                        $month,
                        $day
                    );
                }

                return $this->normalizeOnThisDay(
                    $data,
                    $language,
                    $month,
                    $day
                );
            }
        );

        if (!is_array($result)) {
            throw new RuntimeException(
                'On-this-day cache returned invalid data.'
            );
        }

        return $result;
    }

    public function detectLanguage(string $text): string
    {
        return preg_match('/[\x{0600}-\x{06FF}]/u', $text) === 1
            ? 'fa'
            : 'en';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchRemote(
        string $query,
        string $language,
        int $limit
    ): array {
        $url = $this->actionApi($language)
            . '?'
            . http_build_query(
                [
                    'action' => 'query',
                    'generator' => 'search',
                    'gsrsearch' => $query,
                    'gsrnamespace' => 0,
                    'gsrlimit' => $limit,
                    'prop' => 'extracts|pageimages|info',
                    'exintro' => 1,
                    'explaintext' => 1,
                    'exsentences' => 5,
                    'piprop' => 'thumbnail|original',
                    'pithumbsize' => 600,
                    'inprop' => 'url',
                    'redirects' => 1,
                    'format' => 'json',
                    'formatversion' => 2,
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            );

        $data = $this->http
            ->get($url)
            ->requireSuccess()
            ->jsonArray();

        $pages = $data['query']['pages'] ?? null;

        if (!is_array($pages)) {
            return [];
        }

        $result = [];

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }

            $normalized = $this->normalizePage(
                $page,
                $language
            );

            if ($normalized['title'] !== '') {
                $result[] = $normalized;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function normalizePage(
        array $page,
        string $language
    ): array {
        $title = trim((string) ($page['title'] ?? ''));
        $url = trim((string) ($page['fullurl'] ?? ''));

        if ($url === '' && $title !== '') {
            $url = sprintf(
                'https://%s.wikipedia.org/wiki/%s',
                $language,
                rawurlencode(str_replace(' ', '_', $title))
            );
        }

        $thumbnail = $page['thumbnail']['source'] ?? null;

        return [
            'title' => $title,
            'extract' => trim((string) ($page['extract'] ?? '')),
            'url' => $url,
            'thumbnail' => is_string($thumbnail)
                && filter_var($thumbnail, FILTER_VALIDATE_URL)
                ? $thumbnail
                : null,
            'language' => $language,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function onThisDayRemote(
        string $language,
        int $month,
        int $day
    ): array {
        $url = sprintf(
            'https://%s.wikipedia.org/api/rest_v1/feed/onthisday/all/%02d/%02d',
            $language,
            $month,
            $day
        );

        $data = $this->http
            ->get($url, ['Accept' => 'application/json'])
            ->requireSuccess()
            ->jsonArray();

        return $this->normalizeOnThisDay(
            $data,
            $language,
            $month,
            $day
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeOnThisDay(
        array $data,
        string $language,
        int $month,
        int $day
    ): array {
        $result = [
            'selected' => [],
            'births' => [],
            'deaths' => [],
            'holidays' => [],
            'language' => $language,
            'month' => $month,
            'day' => $day,
        ];

        foreach (['selected', 'births', 'deaths', 'holidays'] as $section) {
            $items = $data[$section] ?? [];

            if (!is_array($items)) {
                continue;
            }

            foreach (array_slice($items, 0, 12) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $result[$section][] = [
                    'year' => $item['year'] ?? '',
                    'text' => trim((string) ($item['text'] ?? '')),
                    'pages' => is_array($item['pages'] ?? null)
                        ? $item['pages']
                        : [],
                ];
            }
        }

        return $result;
    }

    private function actionApi(string $language): string
    {
        return 'https://'
            . $this->language($language)
            . '.wikipedia.org/w/api.php';
    }

    private function language(string $language): string
    {
        $language = mb_strtolower(trim($language));

        return in_array($language, ['fa', 'en'], true)
            ? $language
            : 'fa';
    }
}
