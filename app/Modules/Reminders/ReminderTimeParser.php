<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Reminders;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class ReminderTimeParser
{
    /**
     * @return array{
     *     scheduled_at: int,
     *     text: string,
     *     timezone: string,
     *     display_time: string
     * }
     */
    public function parse(
        string $input,
        string $timezone,
        int $maxFutureDays,
        ?DateTimeImmutable $now = null
    ): array {
        $input = $this->normalize(
            trim($input)
        );

        if ($input === '') {
            throw new InvalidArgumentException(
                'ورودی یادآور خالی است.'
            );
        }

        $timezoneObject = $this->timezone(
            $timezone
        );

        $now = $now !== null
            ? $now->setTimezone($timezoneObject)
            : new DateTimeImmutable(
                'now',
                $timezoneObject
            );

        $parsed = $this->parseRelative(
            $input,
            $now
        ) ?? $this->parseNamedDay(
            $input,
            $now
        ) ?? $this->parseAbsolute(
            $input,
            $timezoneObject
        );

        if ($parsed === null) {
            throw new InvalidArgumentException(
                "فرمت زمان قابل تشخیص نیست.\n"
                . "نمونه: 10m خرید شیر، "
                . "فردا 09:00 جلسه، "
                . "2026-07-15 18:30 تماس"
            );
        }

        $scheduledAt =
            $parsed['date']->getTimestamp();

        if ($scheduledAt <= $now->getTimestamp()) {
            throw new InvalidArgumentException(
                'زمان یادآور باید در آینده باشد.'
            );
        }

        $maxFutureDays = max(
            1,
            min(3650, $maxFutureDays)
        );

        $maximumTimestamp = $now
            ->modify(
                '+' . $maxFutureDays . ' days'
            )
            ->getTimestamp();

        if ($scheduledAt > $maximumTimestamp) {
            throw new InvalidArgumentException(
                "زمان یادآور نباید بیشتر از "
                . "{$maxFutureDays} روز در آینده باشد."
            );
        }

        $text = trim($parsed['text']);

        if ($text === '') {
            throw new InvalidArgumentException(
                'متن یادآور وارد نشده است.'
            );
        }

        return [
            'scheduled_at' => $scheduledAt,
            'text' => $text,
            'timezone' =>
                $timezoneObject->getName(),
            'display_time' =>
                $parsed['date']->format(
                    'Y-m-d H:i'
                ),
        ];
    }

    /**
     * @return array{
     *     date: DateTimeImmutable,
     *     text: string
     * }|null
     */
    private function parseRelative(
        string $input,
        DateTimeImmutable $now
    ): ?array {
        $matched = preg_match(
            '/^(?:(?:در|in)\s+)?'
            . '(\d+)\s*'
            . '(m|min|mins|minute|minutes|'
            . 'دقیقه|دقيقه|'
            . 'h|hr|hrs|hour|hours|ساعت|'
            . 'd|day|days|روز|'
            . 'w|week|weeks|هفته)'
            . '(?:\s+بعد)?\s+(.+)$/iu',
            $input,
            $matches
        );

        if ($matched !== 1) {
            return null;
        }

        $amount = (int) $matches[1];

        if ($amount < 1) {
            throw new InvalidArgumentException(
                'فاصله زمانی باید بزرگ‌تر از صفر باشد.'
            );
        }

        $unit = mb_strtolower(
            $matches[2]
        );

        $seconds = match ($unit) {
            'm',
            'min',
            'mins',
            'minute',
            'minutes',
            'دقیقه',
            'دقيقه' => $amount * 60,

            'h',
            'hr',
            'hrs',
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
                'واحد فاصله زمانی معتبر نیست.'
            ),
        };

        return [
            'date' => $now->modify(
                '+' . $seconds . ' seconds'
            ),
            'text' => $matches[3],
        ];
    }

    /**
     * @return array{
     *     date: DateTimeImmutable,
     *     text: string
     * }|null
     */
    private function parseNamedDay(
        string $input,
        DateTimeImmutable $now
    ): ?array {
        $matched = preg_match(
            '/^(امروز|فردا|today|tomorrow)'
            . '\s+(?:ساعت\s+|at\s+)?'
            . '(\d{1,2}):(\d{2})'
            . '\s+(.+)$/iu',
            $input,
            $matches
        );

        if ($matched !== 1) {
            return null;
        }

        $hour = (int) $matches[2];
        $minute = (int) $matches[3];

        $this->validateClock(
            $hour,
            $minute
        );

        $keyword = mb_strtolower(
            $matches[1]
        );

        $date = $now->setTime(
            $hour,
            $minute,
            0
        );

        if (
            $keyword === 'فردا'
            || $keyword === 'tomorrow'
        ) {
            $date = $date->modify('+1 day');
        }

        return [
            'date' => $date,
            'text' => $matches[4],
        ];
    }

    /**
     * @return array{
     *     date: DateTimeImmutable,
     *     text: string
     * }|null
     */
    private function parseAbsolute(
        string $input,
        DateTimeZone $timezone
    ): ?array {
        $matched = preg_match(
            '/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})'
            . '[ T]+(\d{1,2}):(\d{2})'
            . '\s+(.+)$/u',
            $input,
            $matches
        );

        if ($matched !== 1) {
            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        $hour = (int) $matches[4];
        $minute = (int) $matches[5];

        $this->validateClock(
            $hour,
            $minute
        );

        if (!checkdate($month, $day, $year)) {
            throw new InvalidArgumentException(
                'تاریخ میلادی واردشده معتبر نیست.'
            );
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-n-j H:i',
            "{$year}-{$month}-{$day} "
            . sprintf(
                '%02d:%02d',
                $hour,
                $minute
            ),
            $timezone
        );

        if (!$date instanceof DateTimeImmutable) {
            throw new InvalidArgumentException(
                'تاریخ و ساعت قابل پردازش نیست.'
            );
        }

        return [
            'date' => $date,
            'text' => $matches[6],
        ];
    }

    private function validateClock(
        int $hour,
        int $minute
    ): void {
        if (
            $hour < 0
            || $hour > 23
            || $minute < 0
            || $minute > 59
        ) {
            throw new InvalidArgumentException(
                'ساعت باید بین 00:00 تا 23:59 باشد.'
            );
        }
    }

    private function timezone(
        string $timezone
    ): DateTimeZone {
        $timezone = trim($timezone);

        if ($timezone === '') {
            $timezone = 'Asia/Tehran';
        }

        try {
            return new DateTimeZone($timezone);
        } catch (Throwable) {
            return new DateTimeZone(
                'Asia/Tehran'
            );
        }
    }

    private function normalize(
        string $value
    ): string {
        $value = strtr(
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
                '：' => ':',
                '／' => '/',
                "\u{200C}" => ' ',
            ]
        );

        return preg_replace(
            '/\s+/u',
            ' ',
            trim($value)
        ) ?? trim($value);
    }
}
