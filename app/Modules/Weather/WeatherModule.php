<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Weather;

use DateTimeImmutable;
use RuntimeException;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\ConversationStateStore;
use SmartToolbox\Core\FileCache;
use SmartToolbox\Core\HttpClient;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\RateLimiter;
use Throwable;

final class WeatherModule implements ModuleInterface
{
    private const STATE_AWAITING_CITY = 'weather.awaiting_city';

    public function __construct(
        private readonly HttpClient $http,
        private readonly FileCache $cache,
        private readonly RateLimiter $rateLimiter,
        private readonly ConversationStateStore $states,
        private readonly string $geocodingEndpoint,
        private readonly string $forecastEndpoint,
        private readonly string $logFile,
        private readonly int $geocodingCacheTtl = 86400,
        private readonly int $forecastCacheTtl = 600,
        private readonly int $stateTtl = 300,
        private readonly int $maxAttempts = 30,
        private readonly int $windowSeconds = 60,
        private readonly int $forecastDays = 4
    ) {
    }

    public function register(CommandRouter $router): void
    {
        $router->command(
            'weather',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleWeatherCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'cancel',
            function (MessageContext $context): void {
                $this->cancel($context);
            }
        );

        $router->text(
            '🌤 آب‌وهوا',
            function (MessageContext $context): void {
                $this->askForCity($context);
            }
        );

        $router->fallbackText(
            function (
                MessageContext $context,
                string $text
            ): bool {
                return $this->handlePendingCity(
                    $context,
                    $text
                );
            }
        );
    }

    private function handleWeatherCommand(
        MessageContext $context,
        string $arguments
    ): void {
        $city = trim($arguments);

        if ($city === '') {
            if ($context->isPrivate()) {
                $this->askForCity($context);

                return;
            }

            $context->reply(
                "نام شهر را بعد از دستور وارد کن.\n\n"
                . "نمونه:\n"
                . "/weather Tehran\n"
                . "/weather تهران"
            );

            return;
        }

        $this->states->clear($context->actorKey());

        $this->sendWeather(
            $context,
            $city
        );
    }

    private function askForCity(MessageContext $context): void
    {
        if (!$context->isPrivate()) {
            $context->reply(
                "در گروه نام شهر را همراه دستور بفرست.\n\n"
                . "نمونه:\n"
                . "/weather Tehran"
            );

            return;
        }

        $this->states->set(
            $context->actorKey(),
            self::STATE_AWAITING_CITY,
            ttlSeconds: $this->stateTtl
        );

        $context->reply(
            "نام شهر را بفرست. 🌍\n\n"
            . "مثلاً:\n"
            . "تهران\n"
            . "Mashhad\n\n"
            . "برای لغو: /cancel",
            [
                'reply_markup' => [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' =>
                        'نام شهر، مثلاً تهران',
                ],
            ]
        );
    }

    private function handlePendingCity(
        MessageContext $context,
        string $text
    ): bool {
        if (!$context->isPrivate()) {
            return false;
        }

        $state = $this->states->get(
            $context->actorKey()
        );

        if (
            $state === null
            || $state['state'] !== self::STATE_AWAITING_CITY
        ) {
            return false;
        }

        $city = trim($text);

        if ($city === '') {
            return true;
        }

        $this->states->clear(
            $context->actorKey()
        );

        $this->sendWeather(
            $context,
            $city
        );

        return true;
    }

    private function cancel(MessageContext $context): void
    {
        $state = $this->states->get(
            $context->actorKey()
        );

        if ($state === null) {
            $context->reply(
                'در حال حاضر فرایند فعالی برای لغو وجود ندارد.'
            );

            return;
        }

        $this->states->clear(
            $context->actorKey()
        );

        $context->reply(
            'عملیات لغو شد. ✅'
        );
    }

    private function sendWeather(
        MessageContext $context,
        string $city
    ): void {
        $city = $this->normalizeCity($city);

        if (mb_strlen($city) < 2) {
            $context->reply(
                'نام شهر باید حداقل دو کاراکتر داشته باشد.'
            );

            return;
        }

        if (mb_strlen($city) > 100) {
            $context->reply(
                'نام شهر بیش از حد طولانی است.'
            );

            return;
        }

        $rateLimit = $this->rateLimiter->attempt(
            'weather:' . $context->actorKey(),
            $this->maxAttempts,
            $this->windowSeconds
        );

        if (!$rateLimit->allowed) {
            $context->reply(
                "درخواست‌های زیادی فرستادی. ⏳\n\n"
                . "حدود {$rateLimit->retryAfter} ثانیه دیگر "
                . 'دوباره امتحان کن.'
            );

            return;
        }

        try {
            $location = $this->findLocation($city);

            if ($location === null) {
                $context->reply(
                    "شهری با نام «{$city}» پیدا نشد. 🔍\n\n"
                    . "املای نام را بررسی کن یا نام انگلیسی "
                    . "شهر را امتحان کن."
                );

                return;
            }

            $forecast = $this->fetchForecast(
                $location
            );

            $context->reply(
                $this->formatWeather(
                    $location,
                    $forecast
                )
            );
        } catch (Throwable $exception) {
            $this->log(
                $city,
                $exception
            );

            $context->reply(
                "فعلاً دریافت اطلاعات آب‌وهوا ممکن نشد. ⚠️\n\n"
                . 'چند لحظه بعد دوباره امتحان کن.'
            );
        }
    }

    private function normalizeCity(string $city): string
    {
        $city = trim($city);

        $city = preg_replace(
            '/\s+/u',
            ' ',
            $city
        ) ?? $city;

        return $city;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLocation(string $city): ?array
    {
        $cacheKey = 'weather.geocoding.'
            . mb_strtolower($city);

        $location = $this->cache->remember(
            $cacheKey,
            $this->geocodingCacheTtl,
            function () use ($city): array|null {
                $url = $this->geocodingEndpoint
                    . '?'
                    . http_build_query(
                        [
                            'name' => $city,
                            'count' => 5,
                            'language' => 'fa',
                            'format' => 'json',
                        ],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    );

                $data = $this->http
                    ->get($url)
                    ->requireSuccess()
                    ->jsonArray();

                $results = $data['results'] ?? null;

                if (
                    !is_array($results)
                    || $results === []
                ) {
                    return null;
                }

                foreach ($results as $result) {
                    if (
                        is_array($result)
                        && is_numeric($result['latitude'] ?? null)
                        && is_numeric($result['longitude'] ?? null)
                        && is_string($result['name'] ?? null)
                    ) {
                        return $result;
                    }
                }

                return null;
            }
        );

        return is_array($location)
            ? $location
            : null;
    }

    /**
     * @param array<string, mixed> $location
     *
     * @return array<string, mixed>
     */
    private function fetchForecast(array $location): array
    {
        $latitude = (float) $location['latitude'];
        $longitude = (float) $location['longitude'];

        $cacheKey = sprintf(
            'weather.forecast.%.4f.%.4f.%d',
            $latitude,
            $longitude,
            $this->forecastDays
        );

        $forecast = $this->cache->remember(
            $cacheKey,
            $this->forecastCacheTtl,
            function () use (
                $latitude,
                $longitude
            ): array {
                $url = $this->forecastEndpoint
                    . '?'
                    . http_build_query(
                        [
                            'latitude' => $latitude,
                            'longitude' => $longitude,
                            'current' => implode(
                                ',',
                                [
                                    'temperature_2m',
                                    'relative_humidity_2m',
                                    'apparent_temperature',
                                    'is_day',
                                    'precipitation',
                                    'weather_code',
                                    'cloud_cover',
                                    'surface_pressure',
                                    'wind_speed_10m',
                                    'wind_direction_10m',
                                ]
                            ),
                            'daily' => implode(
                                ',',
                                [
                                    'weather_code',
                                    'temperature_2m_max',
                                    'temperature_2m_min',
                                    'apparent_temperature_max',
                                    'apparent_temperature_min',
                                    'precipitation_sum',
                                    'precipitation_probability_max',
                                    'wind_speed_10m_max',
                                    'sunrise',
                                    'sunset',
                                ]
                            ),
                            'timezone' => 'auto',
                            'forecast_days' => max(
                                1,
                                min(7, $this->forecastDays)
                            ),
                        ],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    );

                return $this->http
                    ->get($url)
                    ->requireSuccess()
                    ->jsonArray();
            }
        );

        if (!is_array($forecast)) {
            throw new RuntimeException(
                'Weather forecast cache returned invalid data.'
            );
        }

        return $forecast;
    }

    /**
     * @param array<string, mixed> $location
     * @param array<string, mixed> $forecast
     */
    private function formatWeather(
        array $location,
        array $forecast
    ): string {
        $current = $forecast['current'] ?? null;
        $daily = $forecast['daily'] ?? null;

        if (!is_array($current) || !is_array($daily)) {
            throw new RuntimeException(
                'Weather provider returned an unexpected response.'
            );
        }

        $weatherCode = (int) (
            $current['weather_code'] ?? -1
        );

        $condition = $this->weatherCondition(
            $weatherCode,
            (int) ($current['is_day'] ?? 1) === 1
        );

        $temperature = $this->number(
            $current['temperature_2m'] ?? null,
            1
        );

        $apparentTemperature = $this->number(
            $current['apparent_temperature'] ?? null,
            1
        );

        $humidity = $this->number(
            $current['relative_humidity_2m'] ?? null
        );

        $windSpeed = $this->number(
            $current['wind_speed_10m'] ?? null,
            1
        );

        $windDirection = $this->windDirection(
            (float) ($current['wind_direction_10m'] ?? 0)
        );

        $precipitation = $this->number(
            $current['precipitation'] ?? null,
            1
        );

        $cloudCover = $this->number(
            $current['cloud_cover'] ?? null
        );

        $pressure = $this->number(
            $current['surface_pressure'] ?? null
        );

        $locationTitle = $this->locationTitle(
            $location
        );

        $localTime = str_replace(
            'T',
            ' ',
            (string) ($current['time'] ?? '')
        );

        $timezone = (string) (
            $forecast['timezone'] ?? ''
        );

        $message = "🌤 آب‌وهوای {$locationTitle}\n\n"
            . "{$condition['emoji']} {$condition['text']}\n"
            . "🌡 دما: {$temperature}°C\n"
            . "🤔 دمای محسوس: {$apparentTemperature}°C\n"
            . "💧 رطوبت: {$humidity}٪\n"
            . "💨 باد: {$windSpeed} km/h، {$windDirection}\n"
            . "🌧 بارش فعلی: {$precipitation} mm\n"
            . "☁️ پوشش ابر: {$cloudCover}٪\n"
            . "🔽 فشار سطح: {$pressure} hPa";

        if ($localTime !== '') {
            $message .= "\n🕒 زمان محلی: {$localTime}";
        }

        if ($timezone !== '') {
            $message .= "\n🌐 منطقه زمانی: {$timezone}";
        }

        $message .= "\n\n📅 پیش‌بینی روزهای آینده:\n";

        $dates = $daily['time'] ?? [];
        $codes = $daily['weather_code'] ?? [];
        $maxTemperatures = $daily['temperature_2m_max'] ?? [];
        $minTemperatures = $daily['temperature_2m_min'] ?? [];
        $precipitationProbabilities =
            $daily['precipitation_probability_max'] ?? [];
        $precipitationSums =
            $daily['precipitation_sum'] ?? [];
        $maxWinds =
            $daily['wind_speed_10m_max'] ?? [];

        if (!is_array($dates)) {
            throw new RuntimeException(
                'Weather daily forecast is invalid.'
            );
        }

        $limit = min(
            count($dates),
            max(1, min(7, $this->forecastDays))
        );

        for ($index = 0; $index < $limit; $index++) {
            $date = (string) ($dates[$index] ?? '');
            $dayCondition = $this->weatherCondition(
                (int) ($codes[$index] ?? -1),
                true
            );

            $dayName = $this->dayName($date);
            $dateLabel = $this->dateLabel($date);

            $maximum = $this->number(
                $maxTemperatures[$index] ?? null,
                1
            );

            $minimum = $this->number(
                $minTemperatures[$index] ?? null,
                1
            );

            $rainChance = $this->number(
                $precipitationProbabilities[$index] ?? null
            );

            $rainSum = $this->number(
                $precipitationSums[$index] ?? null,
                1
            );

            $maximumWind = $this->number(
                $maxWinds[$index] ?? null,
                1
            );

            $message .= "\n"
                . "{$dayCondition['emoji']} {$dayName} {$dateLabel}\n"
                . "   {$dayCondition['text']} | "
                . "کمینه {$minimum}°، بیشینه {$maximum}°\n"
                . "   احتمال بارش {$rainChance}٪، "
                . "بارش {$rainSum} mm، "
                . "باد تا {$maximumWind} km/h\n";
        }

        $message .= "\nداده‌ها: Open-Meteo";

        return trim($message);
    }

    /**
     * @param array<string, mixed> $location
     */
    private function locationTitle(array $location): string
    {
        $parts = [];

        foreach (
            [
                $location['name'] ?? null,
                $location['admin1'] ?? null,
                $location['country'] ?? null,
            ] as $part
        ) {
            if (
                is_string($part)
                && trim($part) !== ''
                && !in_array(trim($part), $parts, true)
            ) {
                $parts[] = trim($part);
            }
        }

        return $parts !== []
            ? implode('، ', $parts)
            : 'موقعیت انتخاب‌شده';
    }

    /**
     * @return array{emoji: string, text: string}
     */
    private function weatherCondition(
        int $code,
        bool $isDay
    ): array {
        return match ($code) {
            0 => [
                'emoji' => $isDay ? '☀️' : '🌙',
                'text' => 'صاف',
            ],
            1 => [
                'emoji' => $isDay ? '🌤' : '🌙',
                'text' => 'عمدتاً صاف',
            ],
            2 => [
                'emoji' => '⛅️',
                'text' => 'نیمه‌ابری',
            ],
            3 => [
                'emoji' => '☁️',
                'text' => 'ابری',
            ],
            45, 48 => [
                'emoji' => '🌫',
                'text' => 'مه‌آلود',
            ],
            51, 53, 55 => [
                'emoji' => '🌦',
                'text' => 'نم‌نم باران',
            ],
            56, 57 => [
                'emoji' => '🌧',
                'text' => 'نم‌نم باران یخ‌زن',
            ],
            61, 63, 65 => [
                'emoji' => '🌧',
                'text' => 'بارانی',
            ],
            66, 67 => [
                'emoji' => '🌧',
                'text' => 'باران یخ‌زن',
            ],
            71, 73, 75 => [
                'emoji' => '🌨',
                'text' => 'برفی',
            ],
            77 => [
                'emoji' => '❄️',
                'text' => 'دانه‌های برف',
            ],
            80, 81, 82 => [
                'emoji' => '🌦',
                'text' => 'رگبار باران',
            ],
            85, 86 => [
                'emoji' => '🌨',
                'text' => 'رگبار برف',
            ],
            95 => [
                'emoji' => '⛈',
                'text' => 'رعدوبرق',
            ],
            96, 99 => [
                'emoji' => '⛈',
                'text' => 'رعدوبرق همراه تگرگ',
            ],
            default => [
                'emoji' => '🌡',
                'text' => 'وضعیت نامشخص',
            ],
        };
    }

    private function windDirection(float $degrees): string
    {
        $normalized = fmod(
            $degrees + 360.0,
            360.0
        );

        $directions = [
            'شمال',
            'شمال‌شرق',
            'شرق',
            'جنوب‌شرق',
            'جنوب',
            'جنوب‌غرب',
            'غرب',
            'شمال‌غرب',
        ];

        $index = (int) round(
            $normalized / 45
        ) % 8;

        return $directions[$index];
    }

    private function number(
        mixed $value,
        int $decimals = 0
    ): string {
        if (!is_numeric($value)) {
            return '—';
        }

        return number_format(
            (float) $value,
            $decimals,
            '.',
            ''
        );
    }

    private function dayName(string $date): string
    {
        try {
            $day = (new DateTimeImmutable($date))
                ->format('D');
        } catch (Throwable) {
            return '';
        }

        return match ($day) {
            'Sat' => 'شنبه',
            'Sun' => 'یکشنبه',
            'Mon' => 'دوشنبه',
            'Tue' => 'سه‌شنبه',
            'Wed' => 'چهارشنبه',
            'Thu' => 'پنجشنبه',
            'Fri' => 'جمعه',
            default => '',
        };
    }

    private function dateLabel(string $date): string
    {
        try {
            return (new DateTimeImmutable($date))
                ->format('m/d');
        } catch (Throwable) {
            return $date;
        }
    }

    private function log(
        string $city,
        Throwable $exception
    ): void {
        $directory = dirname(
            $this->logFile
        );

        if (!is_dir($directory)) {
            @mkdir(
                $directory,
                0700,
                true
            );
        }

        $entry = sprintf(
            "[%s] [city:%s] %s\n",
            date(DATE_ATOM),
            str_replace(
                ["\r", "\n"],
                ' ',
                mb_substr($city, 0, 100)
            ),
            $exception->getMessage()
        );

        @file_put_contents(
            $this->logFile,
            $entry,
            FILE_APPEND | LOCK_EX
        );
    }
}
