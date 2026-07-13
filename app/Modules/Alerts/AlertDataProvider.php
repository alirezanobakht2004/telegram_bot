<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Alerts;

use RuntimeException;
use SmartToolbox\Core\FileCache;
use SmartToolbox\Core\HttpClient;
use SmartToolbox\Modules\Countries\CountryProviderInterface;
use SmartToolbox\Modules\Currency\CurrencyProviderInterface;

final class AlertDataProvider
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly FileCache $cache,
        private readonly CurrencyProviderInterface $currency,
        private readonly CountryProviderInterface $countries,
        private readonly string $geocodingEndpoint,
        private readonly string $forecastEndpoint,
        private readonly int $weatherCacheTtl = 120,
        private readonly int $currencyCacheTtl = 900,
        private readonly int $countryCacheTtl = 21600
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function weather(string $city): array
    {
        $city = trim($city);

        if (mb_strlen($city) < 2 || mb_strlen($city) > 150) {
            throw new RuntimeException(
                'نام شهر معتبر نیست.'
            );
        }

        $key = 'alerts.weather.' . hash(
            'sha256',
            mb_strtolower($city)
        );

        $value = $this->cache->remember(
            $key,
            max(30, $this->weatherCacheTtl),
            function () use ($city): array {
                $geocodingUrl = rtrim(
                    $this->geocodingEndpoint,
                    '?'
                ) . '?' . http_build_query(
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

                $place = $geocoding['results'][0] ?? null;

                if (!is_array($place)) {
                    throw new RuntimeException(
                        'شهر پیدا نشد.'
                    );
                }

                $latitude = $place['latitude'] ?? null;
                $longitude = $place['longitude'] ?? null;

                if (!is_numeric($latitude) || !is_numeric($longitude)) {
                    throw new RuntimeException(
                        'مختصات شهر معتبر نیست.'
                    );
                }

                $forecastUrl = rtrim(
                    $this->forecastEndpoint,
                    '?'
                ) . '?' . http_build_query(
                    [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'current' => implode(',', [
                            'temperature_2m',
                            'apparent_temperature',
                            'relative_humidity_2m',
                            'precipitation',
                            'rain',
                            'snowfall',
                            'weather_code',
                            'wind_speed_10m',
                            'wind_gusts_10m',
                        ]),
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

                $current = $forecast['current'] ?? null;

                if (!is_array($current)) {
                    throw new RuntimeException(
                        'داده آب‌وهوا دریافت نشد.'
                    );
                }

                $code = (int) ($current['weather_code'] ?? -1);
                $precipitation = (float) ($current['precipitation'] ?? 0);
                $rainAmount = (float) ($current['rain'] ?? 0);
                $snowAmount = (float) ($current['snowfall'] ?? 0);
                $rain = $rainAmount > 0
                    || $precipitation > 0
                    || ($code >= 51 && $code <= 67)
                    || ($code >= 80 && $code <= 82)
                    || ($code >= 95 && $code <= 99);
                $snow = $snowAmount > 0
                    || ($code >= 71 && $code <= 77)
                    || ($code >= 85 && $code <= 86);

                $tags = [];

                if ($rain) {
                    $tags[] = 'rain';
                }

                if ($snow) {
                    $tags[] = 'snow';
                }

                if ($tags === []) {
                    $tags[] = $this->conditionTag($code);
                }

                return [
                    'city' => trim((string) ($place['name'] ?? $city)),
                    'country' => trim((string) ($place['country'] ?? '')),
                    'timezone' => (string) ($forecast['timezone'] ?? 'UTC'),
                    'temperature' => (float) ($current['temperature_2m'] ?? 0),
                    'apparent_temperature' => (float) ($current['apparent_temperature'] ?? 0),
                    'humidity' => (int) ($current['relative_humidity_2m'] ?? 0),
                    'precipitation' => $precipitation,
                    'rain_amount' => $rainAmount,
                    'snow_amount' => $snowAmount,
                    'wind' => (float) ($current['wind_speed_10m'] ?? 0),
                    'gust' => (float) ($current['wind_gusts_10m'] ?? 0),
                    'weather_code' => $code,
                    'condition' => $this->conditionLabel($code),
                    'condition_tags' => implode(',', $tags),
                    'rain' => $rain,
                    'snow' => $snow,
                    'observed_at' => (string) ($current['time'] ?? date(DATE_ATOM)),
                ];
            }
        );

        if (!is_array($value)) {
            throw new RuntimeException(
                'داده کش‌شده آب‌وهوا معتبر نیست.'
            );
        }

        return $value;
    }

    /**
     * @return array{base:string,quote:string,rate:float,date:string}
     */
    public function currency(
        string $base,
        string $quote
    ): array {
        $base = mb_strtoupper(trim($base));
        $quote = mb_strtoupper(trim($quote));
        $key = 'alerts.currency.' . $base . '.' . $quote;

        $value = $this->cache->remember(
            $key,
            max(60, $this->currencyCacheTtl),
            fn (): array => $this->currency->quote($base, $quote)
        );

        if (!is_array($value) || !is_numeric($value['rate'] ?? null)) {
            throw new RuntimeException(
                'نرخ ارز معتبر دریافت نشد.'
            );
        }

        return [
            'base' => (string) ($value['base'] ?? $base),
            'quote' => (string) ($value['quote'] ?? $quote),
            'rate' => (float) $value['rate'],
            'date' => (string) ($value['date'] ?? date('Y-m-d')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function country(string $query): array
    {
        $query = trim($query);
        $key = 'alerts.country.' . hash(
            'sha256',
            mb_strtolower($query)
        );

        $value = $this->cache->remember(
            $key,
            max(300, $this->countryCacheTtl),
            fn (): ?array => $this->countries->find($query)
        );

        if (!is_array($value)) {
            throw new RuntimeException(
                'کشور پیدا نشد.'
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $alert
     * @return array{
     *     value: int|float|string|bool,
     *     display: string,
     *     details: string
     * }
     */
    public function observation(array $alert): array
    {
        $type = (string) ($alert['alert_type'] ?? '');
        $subject = (string) ($alert['subject'] ?? '');

        if ($type === 'currency') {
            $quote = (string) ($alert['secondary_subject'] ?? '');
            $data = $this->currency($subject, $quote);
            $rate = (float) $data['rate'];

            return [
                'value' => $rate,
                'display' => $this->number($rate),
                'details' => "{$data['base']}/{$data['quote']} · {$data['date']}",
            ];
        }

        $data = $this->weather($subject);
        $location = $data['city']
            . ($data['country'] !== '' ? '، ' . $data['country'] : '');

        return match ($type) {
            'temperature' => [
                'value' => (float) $data['temperature'],
                'display' => $this->number((float) $data['temperature']) . '°C',
                'details' => $location . ' · ' . $data['condition'],
            ],
            'wind' => [
                'value' => (float) $data['wind'],
                'display' => $this->number((float) $data['wind']) . ' km/h',
                'details' => $location . ' · تندباد ' . $this->number((float) $data['gust']),
            ],
            'weather_condition' => [
                'value' => (string) $data['condition_tags'],
                'display' => (string) $data['condition'],
                'details' => $location
                    . ' · بارش ' . $this->number((float) $data['precipitation']) . ' mm'
                    . ' · دما ' . $this->number((float) $data['temperature']) . '°C',
            ],
            default => throw new RuntimeException(
                'نوع هشدار پشتیبانی نمی‌شود.'
            ),
        };
    }

    /**
     * @param array<string, mixed> $subscription
     */
    public function subscriptionMessage(array $subscription): string
    {
        $type = (string) ($subscription['subscription_type'] ?? '');
        $subject = (string) ($subscription['subject'] ?? '');

        if ($type === 'weather') {
            $data = $this->weather($subject);
            $location = $data['city']
                . ($data['country'] !== '' ? '، ' . $data['country'] : '');

            return "🌤 گزارش دوره‌ای آب‌وهوا\n\n"
                . "مکان: {$location}\n"
                . "وضعیت: {$data['condition']}\n"
                . 'دما: ' . $this->number((float) $data['temperature']) . "°C\n"
                . 'احساسی: ' . $this->number((float) $data['apparent_temperature']) . "°C\n"
                . 'رطوبت: ' . (int) $data['humidity'] . "%\n"
                . 'بارش: ' . $this->number((float) $data['precipitation']) . " mm\n"
                . 'باد: ' . $this->number((float) $data['wind']) . " km/h\n"
                . "منطقه زمانی: {$data['timezone']}";
        }

        if ($type === 'country') {
            $country = $this->country($subject);
            $name = (string) ($country['name'] ?? $subject);
            $capital = $this->firstText($country['capital'] ?? null);
            $region = (string) ($country['region'] ?? '—');
            $population = is_numeric($country['population'] ?? null)
                ? number_format((int) $country['population'])
                : '—';

            return "🌍 گزارش دوره‌ای کشور\n\n"
                . "کشور: {$name}\n"
                . 'پایتخت: ' . ($capital !== '' ? $capital : '—') . "\n"
                . "منطقه: {$region}\n"
                . "جمعیت: {$population}\n"
                . 'ISO: '
                . (string) ($country['alpha2Code'] ?? '—')
                . ' / '
                . (string) ($country['alpha3Code'] ?? '—');
        }

        throw new RuntimeException(
            'نوع اشتراک پشتیبانی نمی‌شود.'
        );
    }

    private function conditionTag(int $code): string
    {
        return match (true) {
            $code === 0 => 'clear',
            $code >= 1 && $code <= 3 => 'cloud',
            in_array($code, [45, 48], true) => 'fog',
            $code >= 95 => 'storm',
            default => 'other',
        };
    }

    private function conditionLabel(int $code): string
    {
        return match (true) {
            $code === 0 => 'صاف',
            in_array($code, [1, 2], true) => 'کمی ابری',
            $code === 3 => 'ابری',
            in_array($code, [45, 48], true) => 'مه‌آلود',
            $code >= 51 && $code <= 67 => 'بارانی',
            $code >= 71 && $code <= 77 => 'برفی',
            $code >= 80 && $code <= 82 => 'رگبار',
            $code >= 85 && $code <= 86 => 'رگبار برف',
            $code >= 95 => 'رعدوبرق',
            default => 'نامشخص',
        };
    }

    private function firstText(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_array($value) && is_string($value[0] ?? null)) {
            return trim($value[0]);
        }

        return '';
    }

    private function number(float $value): string
    {
        if (!is_finite($value)) {
            return 'نامعتبر';
        }

        return rtrim(
            rtrim(
                number_format($value, 6, '.', ','),
                '0'
            ),
            '.'
        );
    }
}
