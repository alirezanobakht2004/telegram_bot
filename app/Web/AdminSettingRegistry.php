<?php

declare(strict_types=1);

namespace SmartToolbox\Web;

use InvalidArgumentException;

final class AdminSettingRegistry
{
    /**
     * @return array<string, array{
     *     group: string,
     *     label: string,
     *     type: string,
     *     help: string,
     *     min?: int,
     *     max?: int
     * }>
     */
    public function definitions(): array
    {
        return [
            'http.connect_timeout' => [
                'group' => 'HTTP خارجی',
                ...$this->integer(
                    'مهلت اتصال',
                    1,
                    30,
                    'ثانیه'
                ),
            ],
            'http.timeout' => [
                'group' => 'HTTP خارجی',
                ...$this->integer(
                    'مهلت کل درخواست',
                    2,
                    60,
                    'ثانیه'
                ),
            ],
            'http.max_response_bytes' => [
                'group' => 'HTTP خارجی',
                ...$this->integer(
                    'حداکثر حجم پاسخ',
                    65536,
                    5242880,
                    'بایت'
                ),
            ],

            ...$this->module(
                'animals',
                'حیوانات',
                [
                    'cache_ttl' => $this->integer(
                        'TTL کش تصویر',
                        1,
                        86400,
                        'ثانیه'
                    ),
                ],
                1000
            ),
            ...$this->module(
                'weather',
                'آب‌وهوا',
                [
                    'geocoding_cache_ttl' =>
                        $this->integer(
                            'TTL جست‌وجوی شهر',
                            60,
                            2592000,
                            'ثانیه'
                        ),
                    'forecast_cache_ttl' =>
                        $this->integer(
                            'TTL پیش‌بینی',
                            60,
                            86400,
                            'ثانیه'
                        ),
                    'state_ttl' =>
                        $this->integer(
                            'TTL مکالمه مرحله‌ای',
                            30,
                            86400,
                            'ثانیه'
                        ),
                    'forecast_days' =>
                        $this->integer(
                            'تعداد روز پیش‌بینی',
                            1,
                            7,
                            'روز'
                        ),
                ],
                1000
            ),
            ...$this->module(
                'currency',
                'ارز',
                [
                    'rate_cache_ttl' =>
                        $this->integer(
                            'TTL نرخ ارز',
                            60,
                            604800,
                            'ثانیه'
                        ),
                    'state_ttl' =>
                        $this->integer(
                            'TTL مکالمه مرحله‌ای',
                            30,
                            86400,
                            'ثانیه'
                        ),
                ],
                1000
            ),
            ...$this->module(
                'countries',
                'کشورها',
                [
                    'cache_ttl' =>
                        $this->integer(
                            'TTL اطلاعات کشور',
                            60,
                            2592000,
                            'ثانیه'
                        ),
                    'state_ttl' =>
                        $this->integer(
                            'TTL مکالمه مرحله‌ای',
                            30,
                            86400,
                            'ثانیه'
                        ),
                ],
                1000
            ),
            ...$this->module(
                'reminders',
                'یادآورها',
                [
                    'state_ttl' =>
                        $this->integer(
                            'TTL مکالمه مرحله‌ای',
                            30,
                            86400,
                            'ثانیه'
                        ),
                    'max_text_length' =>
                        $this->integer(
                            'حداکثر طول متن',
                            50,
                            3000,
                            'کاراکتر'
                        ),
                    'max_pending_per_user' =>
                        $this->integer(
                            'حداکثر یادآور فعال کاربر',
                            1,
                            500,
                            'یادآور'
                        ),
                    'max_future_days' =>
                        $this->integer(
                            'حداکثر فاصله زمانی',
                            1,
                            3650,
                            'روز'
                        ),
                    'retention_days' =>
                        $this->integer(
                            'نگهداری تاریخچه',
                            1,
                            3650,
                            'روز'
                        ),
                    'worker.batch_size' =>
                        $this->integer(
                            'اندازه Batch Worker',
                            1,
                            50,
                            'یادآور در هر اجرا'
                        ),
                    'worker.max_delivery_attempts' =>
                        $this->integer(
                            'حداکثر تلاش ارسال',
                            1,
                            10,
                            'بار'
                        ),
                    'worker.retry_base_seconds' =>
                        $this->integer(
                            'پایه تأخیر Retry',
                            10,
                            3600,
                            'ثانیه'
                        ),
                    'worker.stale_lock_seconds' =>
                        $this->integer(
                            'انقضای Lock پردازش',
                            60,
                            3600,
                            'ثانیه'
                        ),
                ],
                1000
            ),
            ...$this->module(
                'calculator',
                'ماشین حساب',
                [
                    'state_ttl' =>
                        $this->integer(
                            'TTL مکالمه مرحله‌ای',
                            30,
                            86400,
                            'ثانیه'
                        ),
                    'max_expression_length' =>
                        $this->integer(
                            'حداکثر طول عبارت',
                            20,
                            1000,
                            'کاراکتر'
                        ),
                    'max_conversion_length' =>
                        $this->integer(
                            'حداکثر طول تبدیل',
                            20,
                            500,
                            'کاراکتر'
                        ),
                ],
                2000
            ),
            ...$this->module(
                'utilities',
                'ابزارها',
                [
                    'state_ttl' =>
                        $this->integer(
                            'TTL مکالمه مرحله‌ای',
                            30,
                            86400,
                            'ثانیه'
                        ),
                    'max_input_length' =>
                        $this->integer(
                            'حداکثر طول ورودی',
                            100,
                            3500,
                            'کاراکتر'
                        ),
                    'default_password_length' =>
                        $this->integer(
                            'طول پیش‌فرض رمز',
                            8,
                            128,
                            'کاراکتر'
                        ),
                ],
                2000
            ),
            ...$this->module(
                'settings',
                'تنظیمات کاربران',
                [
                    'state_ttl' =>
                        $this->integer(
                            'TTL مکالمه مرحله‌ای',
                            30,
                            86400,
                            'ثانیه'
                        ),
                    'default_password_length' =>
                        $this->integer(
                            'طول پیش‌فرض رمز',
                            8,
                            128,
                            'کاراکتر'
                        ),
                ],
                null
            ),
            ...$this->module(
                'admin',
                'مدیریت تلگرام',
                [
                    'state_ttl' =>
                        $this->integer(
                            'TTL عملیات مرحله‌ای',
                            60,
                            86400,
                            'ثانیه'
                        ),
                    'broadcast_batch_size' =>
                        $this->integer(
                            'اندازه Batch ارسال',
                            1,
                            20,
                            'گیرنده'
                        ),
                    'max_broadcast_length' =>
                        $this->integer(
                            'حداکثر طول Broadcast',
                            100,
                            3500,
                            'کاراکتر'
                        ),
                ],
                null
            ),
        ];
    }

    public function validate(
        string $key,
        mixed $rawValue
    ): bool|int|string {
        $definition = $this->definitions()[$key]
            ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException(
                'این تنظیم از پنل قابل‌ویرایش نیست.'
            );
        }

        if ($definition['type'] === 'bool') {
            $normalized = is_string($rawValue)
                ? mb_strtolower(trim($rawValue))
                : $rawValue;

            return match ($normalized) {
                true, 1, '1', 'true', 'on', 'yes' => true,
                false, 0, '0', 'false', 'off', 'no' => false,
                default => throw new InvalidArgumentException(
                    'مقدار بولی معتبر نیست.'
                ),
            };
        }

        if ($definition['type'] === 'int') {
            $value = is_string($rawValue)
                ? trim($rawValue)
                : $rawValue;

            if (
                filter_var(
                    $value,
                    FILTER_VALIDATE_INT
                ) === false
            ) {
                throw new InvalidArgumentException(
                    'مقدار باید عدد صحیح باشد.'
                );
            }

            $integer = (int) $value;
            $minimum = $definition['min']
                ?? PHP_INT_MIN;
            $maximum = $definition['max']
                ?? PHP_INT_MAX;

            if (
                $integer < $minimum
                || $integer > $maximum
            ) {
                throw new InvalidArgumentException(
                    "مقدار باید بین {$minimum} و {$maximum} باشد."
                );
            }

            return $integer;
        }

        $value = trim((string) $rawValue);

        if (mb_strlen($value) > 500) {
            throw new InvalidArgumentException(
                'مقدار بیش از حد طولانی است.'
            );
        }

        return $value;
    }

    /**
     * @param array<string, array{
     *     label: string,
     *     type: string,
     *     help: string,
     *     min?: int,
     *     max?: int
     * }> $extra
     *
     * @return array<string, array{
     *     group: string,
     *     label: string,
     *     type: string,
     *     help: string,
     *     min?: int,
     *     max?: int
     * }>
     */
    private function module(
        string $module,
        string $group,
        array $extra,
        ?int $rateMaximum
    ): array {
        $prefix = 'modules.' . $module . '.';

        $definitions = [
            $prefix . 'enabled' => [
                'group' => $group,
                'label' => 'فعال بودن ماژول',
                'type' => 'bool',
                'help' => 'از درخواست بعدی اعمال می‌شود.',
            ],
        ];

        foreach ($extra as $suffix => $definition) {
            $definitions[$prefix . $suffix] = [
                'group' => $group,
                ...$definition,
            ];
        }

        if ($rateMaximum !== null) {
            $definitions[
                $prefix
                . 'rate_limit.max_attempts'
            ] = [
                'group' => $group,
                ...$this->integer(
                    'حداکثر درخواست',
                    1,
                    $rateMaximum,
                    'درخواست در هر پنجره'
                ),
            ];

            $definitions[
                $prefix
                . 'rate_limit.window_seconds'
            ] = [
                'group' => $group,
                ...$this->integer(
                    'پنجره Rate Limit',
                    1,
                    86400,
                    'ثانیه'
                ),
            ];
        }

        return $definitions;
    }

    /**
     * @return array{
     *     label: string,
     *     type: string,
     *     min: int,
     *     max: int,
     *     help: string
     * }
     */
    private function integer(
        string $label,
        int $minimum,
        int $maximum,
        string $help
    ): array {
        return [
            'label' => $label,
            'type' => 'int',
            'min' => $minimum,
            'max' => $maximum,
            'help' => $help,
        ];
    }
}
