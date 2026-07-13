<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Developer;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class CronExpression
{
    /**
     * @return list<DateTimeImmutable>
     */
    public function nextRuns(
        string $expression,
        DateTimeZone $timezone,
        int $count = 5
    ): array {
        $parts = preg_split('/\s+/', trim($expression), -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($parts) || count($parts) !== 5) {
            throw new RuntimeException('Cron expression must contain 5 fields.');
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;
        $fields = [
            $this->parseField($minute, 0, 59, []),
            $this->parseField($hour, 0, 23, []),
            $this->parseField($day, 1, 31, []),
            $this->parseField($month, 1, 12, [
                'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
                'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
                'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
            ]),
            $this->parseField($weekday, 0, 7, [
                'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3,
                'thu' => 4, 'fri' => 5, 'sat' => 6,
            ]),
        ];

        $dayRestricted = trim($day) !== '*';
        $weekdayRestricted = trim($weekday) !== '*';
        $nextMinute = (
            new DateTimeImmutable(
                'now',
                $timezone
            )
        )->modify('+1 minute');

        $current = $nextMinute->setTime(
            (int) $nextMinute->format('H'),
            (int) $nextMinute->format('i'),
            0
        );

        $result = [];
        $maxIterations = 60 * 24 * 366;

        for ($iteration = 0; $iteration < $maxIterations && count($result) < max(1, min(20, $count)); $iteration++) {
            $values = [
                (int) $current->format('i'),
                (int) $current->format('G'),
                (int) $current->format('j'),
                (int) $current->format('n'),
                (int) $current->format('w'),
            ];

            $dayMatches = isset($fields[2][$values[2]]);
            $weekdayMatches = isset($fields[4][$values[4]])
                || ($values[4] === 0 && isset($fields[4][7]));
            $calendarMatches = $dayRestricted && $weekdayRestricted
                ? ($dayMatches || $weekdayMatches)
                : ($dayMatches && $weekdayMatches);

            if (
                isset($fields[0][$values[0]])
                && isset($fields[1][$values[1]])
                && $calendarMatches
                && isset($fields[3][$values[3]])
            ) {
                $result[] = $current;
            }

            $current = $current->modify('+1 minute');
        }

        if ($result === []) {
            throw new RuntimeException('No cron occurrence was found in the next year.');
        }

        return $result;
    }

    /**
     * @param array<string, int> $names
     * @return array<int, true>
     */
    private function parseField(
        string $field,
        int $minimum,
        int $maximum,
        array $names
    ): array {
        $field = mb_strtolower(trim($field));
        foreach ($names as $name => $number) {
            $field = preg_replace('/\b' . preg_quote($name, '/') . '\b/i', (string) $number, $field) ?? $field;
        }

        $values = [];

        foreach (explode(',', $field) as $part) {
            $part = trim($part);
            if ($part === '') {
                throw new RuntimeException('Cron field contains an empty list item.');
            }

            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $stepValue] = array_pad(explode('/', $part, 2), 2, '');
                if (preg_match('/^\d+$/', $stepValue) !== 1 || (int) $stepValue < 1) {
                    throw new RuntimeException('Cron step is invalid.');
                }
                $step = (int) $stepValue;
            }

            if ($part === '*') {
                $start = $minimum;
                $end = $maximum;
            } elseif (preg_match('/^(\d+)-(\d+)$/', $part, $matches) === 1) {
                $start = (int) $matches[1];
                $end = (int) $matches[2];
            } elseif (preg_match('/^\d+$/', $part) === 1) {
                $start = (int) $part;
                $end = $start;
            } else {
                throw new RuntimeException('Cron field syntax is invalid.');
            }

            if ($start < $minimum || $end > $maximum || $start > $end) {
                throw new RuntimeException('Cron field value is outside its allowed range.');
            }

            for ($value = $start; $value <= $end; $value += $step) {
                $values[$value] = true;
            }
        }

        return $values;
    }
}
