<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\MiniApp;

use SmartToolbox\Modules\Alerts\ScheduleCalculator;
use SmartToolbox\Modules\Monitoring\MonitorProbe;
use Throwable;

final class MiniAppApiController
{
    /**
     * @param array<string,mixed> $limits
     */
    public function __construct(
        private readonly MiniAppRepository $repository,
        private readonly ScheduleCalculator $schedule,
        private readonly MonitorProbe $monitorProbe,
        private readonly array $limits = []
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function dispatch(
        string $action,
        string $method,
        array $payload,
        int $userId
    ): array {
        $action = trim($action);
        $method = mb_strtoupper(trim($method));

        return match ($action) {
            'dashboard' => $this->read(
                $method,
                fn (): array => $this->repository
                    ->dashboard($userId)
            ),
            'reminders' => $this->read(
                $method,
                fn (): array => [
                    'items' => $this->repository
                        ->reminders($userId),
                ]
            ),
            'alerts' => $this->read(
                $method,
                fn (): array => [
                    'items' => $this->repository
                        ->alerts($userId),
                ]
            ),
            'subscriptions' => $this->read(
                $method,
                fn (): array => [
                    'items' => $this->repository
                        ->subscriptions($userId),
                ]
            ),
            'monitors' => $this->read(
                $method,
                fn (): array => [
                    'items' => $this->repository
                        ->monitors($userId),
                ]
            ),
            'favorites' => $this->read(
                $method,
                fn (): array => [
                    'items' => $this->repository
                        ->favorites($userId),
                ]
            ),
            'shortcuts' => $this->read(
                $method,
                fn (): array => [
                    'items' => $this->repository
                        ->shortcuts($userId),
                ]
            ),
            'cities' => $this->read(
                $method,
                fn (): array => [
                    'items' => $this->repository
                        ->favorites(
                            $userId,
                            'weather'
                        ),
                ]
            ),
            'currencies' => $this->read(
                $method,
                fn (): array => [
                    'items' => $this->repository
                        ->favorites(
                            $userId,
                            'currency'
                        ),
                ]
            ),
            'countries' => $this->read(
                $method,
                fn (): array => [
                    'items' => $this->repository
                        ->favorites(
                            $userId,
                            'country'
                        ),
                ]
            ),
            'history' => $this->read(
                $method,
                fn (): array => [
                    'items' => $this->repository
                        ->history($userId),
                ]
            ),
            'quiz' => $this->read(
                $method,
                fn (): array => $this->repository
                    ->quiz($userId)
            ),
            'settings' => $this->read(
                $method,
                fn (): array => [
                    'settings' => $this->repository
                        ->settings($userId),
                ]
            ),

            'reminder.create' => $this->write(
                $method,
                fn (): array => $this->createReminder(
                    $userId,
                    $payload
                )
            ),
            'reminder.cancel' => $this->write(
                $method,
                fn (): array => $this->ownedResult(
                    $this->repository->cancelReminder(
                        $userId,
                        $this->positiveId(
                            $payload['id'] ?? null,
                            'reminder_id_invalid'
                        )
                    ),
                    'یادآور قابل لغو پیدا نشد.',
                    'reminder_not_cancellable'
                )
            ),
            'reminder.delete' => $this->write(
                $method,
                fn (): array => $this->ownedResult(
                    $this->repository->deleteReminder(
                        $userId,
                        $this->positiveId(
                            $payload['id'] ?? null,
                            'reminder_id_invalid'
                        )
                    ),
                    'یادآور قابل حذف پیدا نشد.',
                    'reminder_not_deletable'
                )
            ),

            'alert.create' => $this->write(
                $method,
                fn (): array => [
                    'id' => $this->repository
                        ->createAlert(
                            $userId,
                            $payload,
                            $this->limit(
                                'max_alerts_per_user',
                                30
                            ),
                            $this->limit(
                                'default_cooldown_seconds',
                                3600
                            ),
                            (float) (
                                $this->limits[
                                    'default_hysteresis'
                                ] ?? 0.5
                            ),
                            $this->limit(
                                'max_notifications_per_day',
                                3
                            ),
                            $this->limit(
                                'alert_check_interval_seconds',
                                300
                            )
                        ),
                ]
            ),
            'alert.status' => $this->write(
                $method,
                fn (): array => $this->ownedResult(
                    $this->repository->setAlertStatus(
                        $userId,
                        $this->positiveId(
                            $payload['id'] ?? null,
                            'alert_id_invalid'
                        ),
                        $this->status(
                            $payload['status'] ?? null
                        )
                    ),
                    'هشدار پیدا نشد یا قابل تغییر نیست.',
                    'alert_not_updated'
                )
            ),

            'subscription.create' => $this->write(
                $method,
                fn (): array => $this->createSubscription(
                    $userId,
                    $payload
                )
            ),
            'subscription.status' => $this->write(
                $method,
                fn (): array => $this->setSubscriptionStatus(
                    $userId,
                    $payload
                )
            ),

            'monitor.create' => $this->write(
                $method,
                fn (): array => $this->createMonitor(
                    $userId,
                    $payload
                )
            ),
            'monitor.status' => $this->write(
                $method,
                fn (): array => $this->ownedResult(
                    $this->repository->setMonitorStatus(
                        $userId,
                        $this->positiveId(
                            $payload['id'] ?? null,
                            'monitor_id_invalid'
                        ),
                        $this->status(
                            $payload['status'] ?? null
                        )
                    ),
                    'مانیتور پیدا نشد یا قابل تغییر نیست.',
                    'monitor_not_updated'
                )
            ),

            'favorite.create' => $this->write(
                $method,
                fn (): array => $this->createFavorite(
                    $userId,
                    $payload
                )
            ),
            'favorite.pin' => $this->write(
                $method,
                fn (): array => $this->ownedResult(
                    $this->repository->setFavoritePinned(
                        $userId,
                        $this->positiveId(
                            $payload['id'] ?? null,
                            'favorite_id_invalid'
                        ),
                        $this->boolean(
                            $payload['pinned'] ?? false
                        )
                    ),
                    'علاقه‌مندی پیدا نشد.',
                    'favorite_not_updated'
                )
            ),
            'favorite.delete' => $this->write(
                $method,
                fn (): array => $this->ownedResult(
                    $this->repository->deleteFavorite(
                        $userId,
                        $this->positiveId(
                            $payload['id'] ?? null,
                            'favorite_id_invalid'
                        )
                    ),
                    'علاقه‌مندی پیدا نشد.',
                    'favorite_not_deleted'
                )
            ),
            'city.save' => $this->write(
                $method,
                fn (): array => $this->saveCity(
                    $userId,
                    $payload
                )
            ),
            'currency.save' => $this->write(
                $method,
                fn (): array => $this->saveCurrency(
                    $userId,
                    $payload
                )
            ),
            'country.save' => $this->write(
                $method,
                fn (): array => $this->saveCountry(
                    $userId,
                    $payload
                )
            ),

            'shortcut.save' => $this->write(
                $method,
                fn (): array => [
                    'id' => $this->repository
                        ->saveShortcut(
                            $userId,
                            (string) (
                                $payload['name'] ?? ''
                            ),
                            (string) (
                                $payload['command'] ?? ''
                            ),
                            $this->limit(
                                'max_shortcuts',
                                30
                            )
                        ),
                ]
            ),
            'shortcut.delete' => $this->write(
                $method,
                fn (): array => $this->ownedResult(
                    $this->repository->deleteShortcut(
                        $userId,
                        (string) (
                            $payload['name'] ?? ''
                        )
                    ),
                    'میان‌بر پیدا نشد.',
                    'shortcut_not_deleted'
                )
            ),
            'history.clear' => $this->write(
                $method,
                fn (): array => [
                    'deleted' => $this->repository
                        ->clearHistory($userId),
                ]
            ),
            'settings.update' => $this->write(
                $method,
                fn (): array => [
                    'settings' => $this->repository
                        ->updateSettings(
                            $userId,
                            $this->stringMap(
                                $payload['settings'] ?? []
                            )
                        ),
                ]
            ),

            default => throw new MiniAppException(
                'عملیات Mini App شناخته نشد.',
                'action_not_found',
                404
            ),
        };
    }

    /**
     * @param callable():array<string,mixed> $callback
     * @return array<string,mixed>
     */
    private function read(
        string $method,
        callable $callback
    ): array {
        if ($method !== 'GET') {
            throw new MiniAppException(
                'متد این عملیات باید GET باشد.',
                'method_not_allowed',
                405
            );
        }

        return $callback();
    }

    /**
     * @param callable():array<string,mixed> $callback
     * @return array<string,mixed>
     */
    private function write(
        string $method,
        callable $callback
    ): array {
        if ($method !== 'POST') {
            throw new MiniAppException(
                'متد این عملیات باید POST باشد.',
                'method_not_allowed',
                405
            );
        }

        return $callback();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function createReminder(
        int $userId,
        array $payload
    ): array {
        $scheduledAt = $payload['scheduled_at'] ?? null;

        if (
            !is_int($scheduledAt)
            && !(
                is_string($scheduledAt)
                && preg_match('/^\d+$/', $scheduledAt) === 1
            )
        ) {
            throw new MiniAppException(
                'زمان یادآور معتبر نیست.',
                'reminder_time_invalid'
            );
        }

        $timezone = trim(
            (string) (
                $payload['timezone'] ?? 'Asia/Tehran'
            )
        );

        try {
            new \DateTimeZone($timezone);
        } catch (Throwable) {
            throw new MiniAppException(
                'منطقه زمانی یادآور معتبر نیست.',
                'timezone_invalid'
            );
        }

        return [
            'id' => $this->repository
                ->createReminder(
                    $userId,
                    (string) ($payload['text'] ?? ''),
                    (int) $scheduledAt,
                    $timezone,
                    $this->limit(
                        'reminder_max_future_days',
                        365
                    ),
                    $this->limit(
                        'reminder_max_pending',
                        50
                    ),
                    $this->limit(
                        'reminder_max_text_length',
                        1000
                    )
                ),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function createSubscription(
        int $userId,
        array $payload
    ): array {
        $frequency = trim(
            (string) (
                $payload['frequency'] ?? ''
            )
        );
        $scheduleTime = trim(
            (string) (
                $payload['schedule_time'] ?? ''
            )
        );
        $timezone = trim(
            (string) (
                $payload['timezone'] ?? 'Asia/Tehran'
            )
        );
        $weekday = isset($payload['weekday'])
            && $payload['weekday'] !== ''
            ? (int) $payload['weekday']
            : null;
        $monthDay = isset($payload['month_day'])
            && $payload['month_day'] !== ''
            ? (int) $payload['month_day']
            : null;

        try {
            $nextRunAt = $this->schedule->nextRun(
                $frequency,
                $scheduleTime,
                $timezone,
                $weekday,
                $monthDay
            );
        } catch (Throwable $exception) {
            throw new MiniAppException(
                $exception->getMessage(),
                'subscription_schedule_invalid'
            );
        }

        return [
            'id' => $this->repository
                ->createSubscription(
                    $userId,
                    $payload + [
                        'frequency' => $frequency,
                        'schedule_time' => $scheduleTime,
                        'timezone' => $timezone,
                        'weekday' => $weekday,
                        'month_day' => $monthDay,
                    ],
                    $nextRunAt,
                    $this->limit(
                        'max_subscriptions_per_user',
                        20
                    )
                ),
            'next_run_at' => $nextRunAt,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function setSubscriptionStatus(
        int $userId,
        array $payload
    ): array {
        $id = $this->positiveId(
            $payload['id'] ?? null,
            'subscription_id_invalid'
        );
        $status = $this->status(
            $payload['status'] ?? null
        );
        $nextRunAt = null;

        if ($status === 'active') {
            $items = $this->repository
                ->subscriptions($userId);
            $target = null;

            foreach ($items as $item) {
                if ((int) $item['id'] === $id) {
                    $target = $item;
                    break;
                }
            }

            if (!is_array($target)) {
                throw new MiniAppException(
                    'اشتراک پیدا نشد.',
                    'subscription_not_found',
                    404
                );
            }

            try {
                $nextRunAt = $this->schedule
                    ->nextRun(
                        (string) $target['frequency'],
                        (string) $target['schedule_time'],
                        (string) $target['timezone'],
                        $target['weekday'] !== null
                            ? (int) $target['weekday']
                            : null,
                        $target['month_day'] !== null
                            ? (int) $target['month_day']
                            : null
                    );
            } catch (Throwable $exception) {
                throw new MiniAppException(
                    $exception->getMessage(),
                    'subscription_schedule_invalid'
                );
            }
        }

        return $this->ownedResult(
            $this->repository->setSubscriptionStatus(
                $userId,
                $id,
                $status,
                $nextRunAt
            ),
            'اشتراک پیدا نشد یا قابل تغییر نیست.',
            'subscription_not_updated'
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function createMonitor(
        int $userId,
        array $payload
    ): array {
        $url = trim(
            (string) ($payload['url'] ?? '')
        );
        $interval = (int) (
            $payload['interval_seconds'] ?? 300
        );
        $minimum = $this->limit(
            'monitor_minimum_interval_seconds',
            300
        );
        $maximum = $this->limit(
            'monitor_maximum_interval_seconds',
            86400
        );

        if ($interval < $minimum || $interval > $maximum) {
            throw new MiniAppException(
                "فاصله مانیتور باید بین {$minimum} و {$maximum} ثانیه باشد.",
                'monitor_interval_invalid'
            );
        }

        try {
            $normalized = $this->monitorProbe
                ->normalizeUrl($url);
        } catch (Throwable $exception) {
            throw new MiniAppException(
                $exception->getMessage(),
                'monitor_url_invalid'
            );
        }

        $timezone = trim(
            (string) (
                $payload['timezone'] ?? 'Asia/Tehran'
            )
        );

        try {
            new \DateTimeZone($timezone);
        } catch (Throwable) {
            throw new MiniAppException(
                'منطقه زمانی مانیتور معتبر نیست.',
                'timezone_invalid'
            );
        }

        return [
            'id' => $this->repository
                ->createMonitor(
                    $userId,
                    $normalized,
                    $this->canonicalUrl(
                        $normalized
                    ),
                    $interval,
                    $timezone,
                    $this->limit(
                        'max_monitors_per_user',
                        20
                    )
                ),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function createFavorite(
        int $userId,
        array $payload
    ): array {
        $type = mb_strtolower(
            trim((string) ($payload['type'] ?? ''))
        );
        $value = trim(
            (string) ($payload['value'] ?? '')
        );

        if ($value === '' || mb_strlen($value) > 500) {
            throw new MiniAppException(
                'مقدار علاقه‌مندی معتبر نیست.',
                'favorite_value_invalid'
            );
        }

        $label = $this->favoriteEmoji($type)
            . ' '
            . mb_substr($value, 0, 150);

        return [
            'id' => $this->repository
                ->saveFavorite(
                    $userId,
                    $type,
                    $type . ' ' . $value,
                    $label,
                    ['value' => $value],
                    $this->limit(
                        'max_favorites',
                        50
                    )
                ),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function saveCity(
        int $userId,
        array $payload
    ): array {
        $city = trim(
            (string) ($payload['city'] ?? '')
        );

        if ($city === '' || mb_strlen($city) > 150) {
            throw new MiniAppException(
                'نام شهر معتبر نیست.',
                'city_invalid'
            );
        }

        return [
            'id' => $this->repository
                ->saveFavorite(
                    $userId,
                    'weather',
                    'weather ' . $city,
                    '🌤 ' . $city,
                    [
                        'value' => $city,
                        'city' => $city,
                    ],
                    $this->limit(
                        'max_favorites',
                        50
                    )
                ),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function saveCurrency(
        int $userId,
        array $payload
    ): array {
        $base = mb_strtoupper(
            trim((string) ($payload['base'] ?? ''))
        );
        $quote = mb_strtoupper(
            trim((string) ($payload['quote'] ?? ''))
        );

        if (
            preg_match('/^[A-Z]{3}$/', $base) !== 1
            || preg_match('/^[A-Z]{3}$/', $quote) !== 1
            || $base === $quote
        ) {
            throw new MiniAppException(
                'جفت ارز معتبر نیست.',
                'currency_pair_invalid'
            );
        }

        $value = $base . ' ' . $quote;

        return [
            'id' => $this->repository
                ->saveFavorite(
                    $userId,
                    'currency',
                    'currency ' . $value,
                    '💱 ' . $base . ' → ' . $quote,
                    [
                        'value' => $value,
                        'base' => $base,
                        'quote' => $quote,
                    ],
                    $this->limit(
                        'max_favorites',
                        50
                    )
                ),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function saveCountry(
        int $userId,
        array $payload
    ): array {
        $country = trim(
            (string) ($payload['country'] ?? '')
        );

        if ($country === '' || mb_strlen($country) > 150) {
            throw new MiniAppException(
                'نام کشور معتبر نیست.',
                'country_invalid'
            );
        }

        return [
            'id' => $this->repository
                ->saveFavorite(
                    $userId,
                    'country',
                    'country ' . $country,
                    '🌍 ' . $country,
                    [
                        'value' => $country,
                        'country' => $country,
                    ],
                    $this->limit(
                        'max_favorites',
                        50
                    )
                ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ownedResult(
        bool $updated,
        string $message,
        string $errorCode
    ): array {
        if (!$updated) {
            throw new MiniAppException(
                $message,
                $errorCode,
                404
            );
        }

        return ['updated' => true];
    }

    private function positiveId(
        mixed $value,
        string $errorCode
    ): int {
        if (
            is_int($value)
            && $value > 0
        ) {
            return $value;
        }

        if (
            is_string($value)
            && preg_match('/^\d+$/', $value) === 1
            && (int) $value > 0
        ) {
            return (int) $value;
        }

        throw new MiniAppException(
            'شناسه رکورد معتبر نیست.',
            $errorCode
        );
    }

    private function status(mixed $value): string
    {
        $status = mb_strtolower(
            trim((string) $value)
        );

        if (!in_array(
            $status,
            ['active', 'paused', 'cancelled'],
            true
        )) {
            throw new MiniAppException(
                'وضعیت درخواست معتبر نیست.',
                'status_invalid'
            );
        }

        return $status;
    }

    private function boolean(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 'true'
            || $value === 'on';
    }

    /**
     * @return array<string,string>
     */
    private function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            throw new MiniAppException(
                'تنظیمات ارسالی معتبر نیست.',
                'settings_payload_invalid'
            );
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (
                is_string($key)
                && (
                    is_string($item)
                    || is_int($item)
                    || is_float($item)
                    || is_bool($item)
                )
            ) {
                $result[$key] = (string) $item;
            }
        }

        return $result;
    }

    private function limit(
        string $key,
        int $default
    ): int {
        return (int) ($this->limits[$key] ?? $default);
    }

    private function favoriteEmoji(string $type): string
    {
        return match ($type) {
            'weather' => '🌤',
            'currency' => '💱',
            'country' => '🌍',
            'wiki' => '📚',
            'github' => '🐙',
            'calc' => '🧮',
            default => '⭐',
        };
    }

    private function canonicalUrl(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            throw new MiniAppException(
                'URL معتبر نیست.',
                'monitor_url_invalid'
            );
        }

        $scheme = mb_strtolower(
            (string) ($parts['scheme'] ?? '')
        );
        $host = mb_strtolower(
            rtrim((string) ($parts['host'] ?? ''), '.')
        );

        if ($scheme === '' || $host === '') {
            throw new MiniAppException(
                'URL معتبر نیست.',
                'monitor_url_invalid'
            );
        }

        $port = isset($parts['port'])
            ? ':' . (int) $parts['port']
            : '';
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query'])
            ? '?' . $parts['query']
            : '';

        return $scheme
            . '://'
            . $host
            . $port
            . ($path !== '' ? $path : '/')
            . $query;
    }
}
