<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Alerts;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class ScheduleCalculator
{
    private const WEEKDAYS = [
        'sunday' => 0,
        'sun' => 0,
        'یکشنبه' => 0,
        'monday' => 1,
        'mon' => 1,
        'دوشنبه' => 1,
        'tuesday' => 2,
        'tue' => 2,
        'سهشنبه' => 2,
        'سه‌شنبه' => 2,
        'wednesday' => 3,
        'wed' => 3,
        'چهارشنبه' => 3,
        'thursday' => 4,
        'thu' => 4,
        'پنجشنبه' => 4,
        'پنج‌شنبه' => 4,
        'friday' => 5,
        'fri' => 5,
        'جمعه' => 5,
        'saturday' => 6,
        'sat' => 6,
        'شنبه' => 6,
    ];

    public function nextRun(
        string $frequency,
        string $time,
        string $timezone,
        ?int $weekday = null,
        ?int $monthDay = null,
        ?DateTimeImmutable $now = null
    ): int {
        $frequency = mb_strtolower(trim($frequency));
        [$hour, $minute] = $this->clock($time);
        $zone = $this->timezone($timezone);
        $now = ($now ?? new DateTimeImmutable('now', $zone))
            ->setTimezone($zone);

        return match ($frequency) {
            'daily' => $this->daily(
                $now,
                $hour,
                $minute
            )->getTimestamp(),
            'weekly' => $this->weekly(
                $now,
                $hour,
                $minute,
                $weekday
            )->getTimestamp(),
            'monthly' => $this->monthly(
                $now,
                $hour,
                $minute,
                $monthDay
            )->getTimestamp(),
            default => throw new InvalidArgumentException(
                'نوع زمان‌بندی باید daily، weekly یا monthly باشد.'
            ),
        };
    }

    public function weekday(string $value): int
    {
        $normalized = mb_strtolower(
            str_replace([' ', '_'], '', trim($value))
        );

        if (!array_key_exists($normalized, self::WEEKDAYS)) {
            throw new InvalidArgumentException(
                'روز هفته معتبر نیست.'
            );
        }

        return self::WEEKDAYS[$normalized];
    }

    public function weekdayName(int $weekday): string
    {
        return [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ][max(0, min(6, $weekday))];
    }

    /**
     * @return array{0:int,1:int}
     */
    private function clock(string $value): array
    {
        $value = $this->normalizeDigits(trim($value));

        if (
            preg_match(
                '/^(\d{1,2}):(\d{2})$/',
                $value,
                $matches
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'زمان باید با فرمت HH:MM باشد.'
            );
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if (
            $hour < 0
            || $hour > 23
            || $minute < 0
            || $minute > 59
        ) {
            throw new InvalidArgumentException(
                'ساعت واردشده معتبر نیست.'
            );
        }

        return [$hour, $minute];
    }

    private function daily(
        DateTimeImmutable $now,
        int $hour,
        int $minute
    ): DateTimeImmutable {
        $candidate = $now->setTime($hour, $minute, 0);

        return $candidate->getTimestamp() > $now->getTimestamp()
            ? $candidate
            : $candidate->modify('+1 day');
    }

    private function weekly(
        DateTimeImmutable $now,
        int $hour,
        int $minute,
        ?int $weekday
    ): DateTimeImmutable {
        if ($weekday === null || $weekday < 0 || $weekday > 6) {
            throw new InvalidArgumentException(
                'روز هفته برای اشتراک هفتگی لازم است.'
            );
        }

        $currentWeekday = (int) $now->format('w');
        $daysAhead = ($weekday - $currentWeekday + 7) % 7;
        $candidate = $now
            ->modify('+' . $daysAhead . ' days')
            ->setTime($hour, $minute, 0);

        if ($candidate->getTimestamp() <= $now->getTimestamp()) {
            $candidate = $candidate->modify('+7 days');
        }

        return $candidate;
    }

    private function monthly(
        DateTimeImmutable $now,
        int $hour,
        int $minute,
        ?int $monthDay
    ): DateTimeImmutable {
        if ($monthDay === null || $monthDay < 1 || $monthDay > 31) {
            throw new InvalidArgumentException(
                'روز ماه باید بین ۱ تا ۳۱ باشد.'
            );
        }

        $candidate = $this->monthCandidate(
            $now,
            $monthDay,
            $hour,
            $minute
        );

        if ($candidate->getTimestamp() <= $now->getTimestamp()) {
            $candidate = $this->monthCandidate(
                $now->modify('first day of next month'),
                $monthDay,
                $hour,
                $minute
            );
        }

        return $candidate;
    }

    private function monthCandidate(
        DateTimeImmutable $reference,
        int $monthDay,
        int $hour,
        int $minute
    ): DateTimeImmutable {
        $year = (int) $reference->format('Y');
        $month = (int) $reference->format('n');
        $lastDay = (int) $reference
            ->modify('last day of this month')
            ->format('j');
        $day = min($monthDay, $lastDay);

        return $reference
            ->setDate($year, $month, $day)
            ->setTime($hour, $minute, 0);
    }

    private function timezone(string $value): DateTimeZone
    {
        try {
            return new DateTimeZone(trim($value));
        } catch (Throwable) {
            return new DateTimeZone('Asia/Tehran');
        }
    }

    private function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
