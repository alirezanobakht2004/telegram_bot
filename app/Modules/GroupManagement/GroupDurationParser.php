<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\GroupManagement;

use InvalidArgumentException;

final class GroupDurationParser
{
    public function parse(
        string $value,
        int $minimumSeconds = 30,
        int $maximumSeconds = 31622400,
        bool $allowForever = true
    ): ?int {
        $value = $this->normalize(
            trim($value)
        );

        if ($value === '') {
            throw new InvalidArgumentException(
                'مدت زمان وارد نشده است.'
            );
        }

        if (
            $allowForever
            && in_array(
                mb_strtolower($value),
                [
                    'forever',
                    'permanent',
                    'دائم',
                    'دایم',
                    'همیشه',
                ],
                true
            )
        ) {
            return null;
        }

        if (
            preg_match(
                '/^(\d+)\s*'
                . '(s|sec|second|seconds|ثانیه|'
                . 'm|min|minute|minutes|دقیقه|'
                . 'h|hr|hour|hours|ساعت|'
                . 'd|day|days|روز|'
                . 'w|week|weeks|هفته)$/iu',
                $value,
                $matches
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'فرمت زمان معتبر نیست؛ نمونه: 10m، 2h، 3d یا forever.'
            );
        }

        $amount = (int) $matches[1];

        if ($amount < 1) {
            throw new InvalidArgumentException(
                'مدت زمان باید بزرگ‌تر از صفر باشد.'
            );
        }

        $unit = mb_strtolower(
            $matches[2]
        );

        $seconds = match ($unit) {
            's',
            'sec',
            'second',
            'seconds',
            'ثانیه' => $amount,

            'm',
            'min',
            'minute',
            'minutes',
            'دقیقه' => $amount * 60,

            'h',
            'hr',
            'hour',
            'hours',
            'ساعت' => $amount * 3600,

            'd',
            'day',
            'days',
            'روز' => $amount * 86400,

            'w',
            'week',
            'weeks',
            'هفته' => $amount * 604800,

            default => throw new InvalidArgumentException(
                'واحد زمان پشتیبانی نمی‌شود.'
            ),
        };

        if ($seconds < $minimumSeconds) {
            throw new InvalidArgumentException(
                "حداقل مدت {$minimumSeconds} ثانیه است."
            );
        }

        if ($seconds > $maximumSeconds) {
            throw new InvalidArgumentException(
                "حداکثر مدت {$maximumSeconds} ثانیه است."
            );
        }

        return $seconds;
    }

    public function parseOptional(
        string $value,
        ?int $default = null,
        int $minimumSeconds = 30,
        int $maximumSeconds = 31622400
    ): ?int {
        $value = trim($value);

        if ($value === '') {
            return $default;
        }

        return $this->parse(
            $value,
            $minimumSeconds,
            $maximumSeconds
        );
    }

    private function normalize(string $value): string
    {
        return strtr(
            $value,
            [
                '۰' => '0',
                '۱' => '1',
                '۲' => '2',
                '۳' => '3',
                '۴' => '4',
                '۵' => '5',
                '۶' => '6',
                '۷' => '7',
                '۸' => '8',
                '۹' => '9',

                '٠' => '0',
                '١' => '1',
                '٢' => '2',
                '٣' => '3',
                '٤' => '4',
                '٥' => '5',
                '٦' => '6',
                '٧' => '7',
                '٨' => '8',
                '٩' => '9',

                'ي' => 'ی',
                'ك' => 'ک',
            ]
        );
    }
}
