<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Inline;

use RuntimeException;
use SmartToolbox\Core\FileCache;
use SmartToolbox\Core\HttpClient;
use SmartToolbox\Modules\Calculator\ExpressionCalculator;
use SmartToolbox\Modules\Countries\CountryProviderInterface;
use SmartToolbox\Modules\Currency\CurrencyProviderInterface;
use SmartToolbox\Modules\GitHub\GitHubClient;
use SmartToolbox\Modules\Wiki\WikiClient;

final class InlineDataService
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly FileCache $cache,
        private readonly CurrencyProviderInterface $currency,
        private readonly CountryProviderInterface $countries,
        private readonly ExpressionCalculator $calculator,
        private readonly WikiClient $wiki,
        private readonly GitHubClient $github,
        private readonly string $geocodingEndpoint,
        private readonly string $forecastEndpoint,
        private readonly int $weatherCacheTtl = 600
    ) {
    }

    /**
     * @return array{
     *     title: string,
     *     description: string,
     *     message: string
     * }
     */
    public function weather(string $city): array
    {
        $city = trim($city);

        if ($city === '') {
            throw new RuntimeException(
                'نام شهر وارد نشده است.'
            );
        }

        $key = 'inline.weather.'
            . hash(
                'sha256',
                mb_strtolower($city)
            );

        $value = $this->cache->remember(
            $key,
            max(60, $this->weatherCacheTtl),
            function () use ($city): array {
                $geocodingUrl = rtrim(
                    $this->geocodingEndpoint,
                    '?'
                ) . '?'
                    . http_build_query(
                        [
                            'name' => $city,
                            'count' => 1,
                            'language' => 'fa',
                            'format' => 'json',
                        ],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    );

                $geocoding = $this->http
                    ->get($geocodingUrl)
                    ->requireSuccess()
                    ->jsonArray();

                $place = $geocoding[
                    'results'
                ][0] ?? null;

                if (!is_array($place)) {
                    throw new RuntimeException(
                        'شهر پیدا نشد.'
                    );
                }

                $latitude = $place[
                    'latitude'
                ] ?? null;
                $longitude = $place[
                    'longitude'
                ] ?? null;

                if (
                    !is_numeric($latitude)
                    || !is_numeric($longitude)
                ) {
                    throw new RuntimeException(
                        'مختصات شهر معتبر نیست.'
                    );
                }

                $forecastUrl = rtrim(
                    $this->forecastEndpoint,
                    '?'
                ) . '?'
                    . http_build_query(
                        [
                            'latitude' => $latitude,
                            'longitude' => $longitude,
                            'current' =>
                                'temperature_2m,apparent_temperature,relative_humidity_2m,precipitation,weather_code,wind_speed_10m',
                            'timezone' => 'auto',
                        ],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    );

                $forecast = $this->http
                    ->get($forecastUrl)
                    ->requireSuccess()
                    ->jsonArray();

                $current = $forecast[
                    'current'
                ] ?? null;

                if (!is_array($current)) {
                    throw new RuntimeException(
                        'اطلاعات هوا دریافت نشد.'
                    );
                }

                $name = trim(
                    (string) (
                        $place['name'] ?? $city
                    )
                );

                $country = trim(
                    (string) (
                        $place['country'] ?? ''
                    )
                );

                return [
                    'name' => $name,
                    'country' => $country,
                    'temperature' => (float) (
                        $current[
                            'temperature_2m'
                        ] ?? 0
                    ),
                    'apparent' => (float) (
                        $current[
                            'apparent_temperature'
                        ] ?? 0
                    ),
                    'humidity' => (int) (
                        $current[
                            'relative_humidity_2m'
                        ] ?? 0
                    ),
                    'precipitation' => (float) (
                        $current[
                            'precipitation'
                        ] ?? 0
                    ),
                    'wind' => (float) (
                        $current[
                            'wind_speed_10m'
                        ] ?? 0
                    ),
                    'code' => (int) (
                        $current[
                            'weather_code'
                        ] ?? -1
                    ),
                    'timezone' => (string) (
                        $forecast['timezone']
                        ?? ''
                    ),
                ];
            }
        );

        if (!is_array($value)) {
            throw new RuntimeException(
                'کش آب‌وهوا معتبر نیست.'
            );
        }

        $location = $value['name']
            . (
                $value['country'] !== ''
                    ? '، ' . $value['country']
                    : ''
            );

        $condition = $this->weatherCondition(
            (int) $value['code']
        );

        $temperature = $this->number(
            (float) $value['temperature']
        );

        return [
            'title' =>
                "🌤 {$location}: {$temperature}°C",
            'description' =>
                "{$condition} · رطوبت "
                . (int) $value['humidity']
                . '% · باد '
                . $this->number(
                    (float) $value['wind']
                )
                . ' km/h',
            'message' =>
                "🌤 آب‌وهوای {$location}\n\n"
                . "وضعیت: {$condition}\n"
                . "دما: {$temperature}°C\n"
                . "دمای احساسی: "
                . $this->number(
                    (float) $value['apparent']
                )
                . "°C\n"
                . "رطوبت: "
                . (int) $value['humidity']
                . "%\n"
                . "بارش: "
                . $this->number(
                    (float) $value[
                        'precipitation'
                    ]
                )
                . " mm\n"
                . "باد: "
                . $this->number(
                    (float) $value['wind']
                )
                . " km/h\n"
                . "منطقه زمانی: "
                . $value['timezone'],
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     description: string,
     *     message: string
     * }
     */
    public function calculation(
        string $expression
    ): array {
        $expression = trim($expression);

        if ($expression === '') {
            throw new RuntimeException(
                'عبارت محاسباتی وارد نشده است.'
            );
        }

        if (mb_strlen($expression) > 500) {
            throw new RuntimeException(
                'عبارت بیش از حد طولانی است.'
            );
        }

        $result = $this->calculator->evaluate(
            $expression
        );

        $formatted = $this->number($result);

        return [
            'title' => "🧮 {$formatted}",
            'description' => $expression,
            'message' =>
                "🧮 محاسبه\n\n"
                . "{$expression}\n=\n"
                . $formatted,
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     description: string,
     *     message: string
     * }
     */
    public function currency(string $arguments): array
    {
        $parts = preg_split(
            '/\s+/u',
            trim($arguments),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if (
            !is_array($parts)
            || !in_array(
                count($parts),
                [2, 3],
                true
            )
        ) {
            throw new RuntimeException(
                'فرمت: currency 100 USD EUR'
            );
        }

        if (count($parts) === 2) {
            $amount = 1.0;
            [$base, $quote] = $parts;
        } else {
            if (!is_numeric($parts[0])) {
                throw new RuntimeException(
                    'مقدار ارز باید عدد باشد.'
                );
            }

            $amount = (float) $parts[0];
            $base = $parts[1];
            $quote = $parts[2];
        }

        if (!is_finite($amount)) {
            throw new RuntimeException(
                'مقدار ارز معتبر نیست.'
            );
        }

        $data = $this->currency->quote(
            $base,
            $quote
        );

        $converted = $amount
            * (float) $data['rate'];

        $amountText = $this->number($amount);
        $convertedText = $this->number(
            $converted
        );

        return [
            'title' =>
                "💱 {$amountText} "
                . $data['base']
                . " = {$convertedText} "
                . $data['quote'],
            'description' =>
                'نرخ مرجع رسمی · '
                . $data['date'],
            'message' =>
                "💱 تبدیل ارز رسمی\n\n"
                . "{$amountText} "
                . $data['base']
                . "\n=\n"
                . "{$convertedText} "
                . $data['quote']
                . "\n\nنرخ: "
                . $this->number(
                    (float) $data['rate']
                )
                . "\nتاریخ: "
                . $data['date'],
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     description: string,
     *     message: string,
     *     thumbnail: ?string
     * }
     */
    public function country(string $query): array
    {
        $country = $this->countries->find(
            $query
        );

        if ($country === null) {
            throw new RuntimeException(
                'کشور پیدا نشد.'
            );
        }

        $name = trim(
            (string) (
                $country['name'] ?? $query
            )
        );

        $capital = $this->firstText(
            $country['capital'] ?? null
        );

        $region = trim(
            (string) (
                $country['region'] ?? ''
            )
        );

        $population = is_numeric(
            $country['population'] ?? null
        )
            ? number_format(
                (int) $country['population']
            )
            : '—';

        $currencyText = $this->currencyList(
            $country['currencies'] ?? []
        );

        $languages = $this->languageList(
            $country['languages'] ?? []
        );

        $flags = $country['flags']
            ?? null;

        $flag = is_array($flags)
            && is_string(
                $flags['png'] ?? null
            )
                ? $flags['png']
                : (
                    is_string(
                        $country['flag'] ?? null
                    )
                        ? $country['flag']
                        : null
                );

        return [
            'title' => "🌍 {$name}",
            'description' =>
                ($capital !== ''
                    ? "پایتخت: {$capital} · "
                    : '')
                . ($region !== ''
                    ? $region
                    : 'اطلاعات کشور'),
            'message' =>
                "🌍 اطلاعات کشور {$name}\n\n"
                . "پایتخت: "
                . ($capital !== ''
                    ? $capital
                    : '—')
                . "\n"
                . "منطقه: "
                . ($region !== ''
                    ? $region
                    : '—')
                . "\n"
                . "جمعیت: {$population}\n"
                . "ارز: "
                . ($currencyText !== ''
                    ? $currencyText
                    : '—')
                . "\n"
                . "زبان‌ها: "
                . ($languages !== ''
                    ? $languages
                    : '—')
                . "\n"
                . "ISO: "
                . trim(
                    (string) (
                        $country[
                            'alpha2Code'
                        ] ?? ''
                    )
                )
                . ' / '
                . trim(
                    (string) (
                        $country[
                            'alpha3Code'
                        ] ?? ''
                    )
                ),
            'thumbnail' =>
                is_string($flag)
                    ? $flag
                    : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function wiki(
        string $query,
        int $limit = 5
    ): array {
        $language =
            $this->wiki->detectLanguage(
                $query
            );

        return $this->wiki->search(
            $query,
            $language,
            $limit
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function github(string $repository): array
    {
        return $this->github->repository(
            $repository
        );
    }

    private function weatherCondition(
        int $code
    ): string {
        return match (true) {
            $code === 0 => 'صاف',
            in_array($code, [1, 2], true) =>
                'کمی ابری',
            $code === 3 => 'ابری',
            in_array($code, [45, 48], true) =>
                'مه‌آلود',
            $code >= 51 && $code <= 67 =>
                'بارانی',
            $code >= 71 && $code <= 77 =>
                'برفی',
            $code >= 80 && $code <= 82 =>
                'رگبار',
            $code >= 85 && $code <= 86 =>
                'رگبار برف',
            $code >= 95 => 'رعدوبرق',
            default => 'نامشخص',
        };
    }

    private function firstText(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (
            is_array($value)
            && is_string($value[0] ?? null)
        ) {
            return trim($value[0]);
        }

        return '';
    }

    private function currencyList(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $result[] = $item;
                continue;
            }

            if (is_array($item)) {
                $code = is_string($key)
                    ? $key
                    : (
                        $item['code']
                        ?? $item['symbol']
                        ?? ''
                    );

                $name = $item['name'] ?? '';

                $text = trim(
                    (string) $code
                    . (
                        $name !== ''
                            ? ' (' . $name . ')'
                            : ''
                    )
                );

                if ($text !== '') {
                    $result[] = $text;
                }
            }
        }

        return implode(
            '، ',
            array_slice($result, 0, 5)
        );
    }

    private function languageList(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $result[] = $item;
            } elseif (is_array($item)) {
                $name = $item['name']
                    ?? $item['nativeName']
                    ?? '';

                if (is_string($name)) {
                    $result[] = $name;
                }
            } elseif (is_string($key)) {
                $result[] = $key;
            }
        }

        return implode(
            '، ',
            array_slice($result, 0, 8)
        );
    }

    private function number(float $value): string
    {
        if (!is_finite($value)) {
            return 'نامعتبر';
        }

        if ($value == 0.0) {
            return '0';
        }

        $absolute = abs($value);

        if (
            $absolute >= 1_000_000_000_000
            || $absolute < 0.00000001
        ) {
            return sprintf('%.12g', $value);
        }

        return rtrim(
            rtrim(
                number_format(
                    $value,
                    8,
                    '.',
                    ','
                ),
                '0'
            ),
            '.'
        );
    }
}
