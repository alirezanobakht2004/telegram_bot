<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Alerts;

use InvalidArgumentException;

final class ConditionEvaluator
{
    /**
     * @return array{
     *     condition: bool,
     *     trigger: bool,
     *     normalized_value: string
     * }
     */
    public function evaluate(
        string $operator,
        int|float|string|bool $current,
        int|float|string|bool|null $target,
        int|float|string|bool|null $previous,
        ?bool $previousCondition,
        float $hysteresis = 0.0
    ): array {
        $operator = mb_strtolower(trim($operator));
        $hysteresis = max(0.0, $hysteresis);

        $numericOperators = ['above', 'below'];
        $numeric = in_array($operator, $numericOperators, true)
            || ($operator === 'equals' && is_numeric($current) && is_numeric($target));

        if ($numeric) {
            if (!is_numeric($current) || !is_numeric($target)) {
                throw new InvalidArgumentException(
                    'این شرط به مقدار عددی نیاز دارد.'
                );
            }

            $currentNumber = (float) $current;
            $targetNumber = (float) $target;
            $condition = match ($operator) {
                'above' => $previousCondition === true
                    ? $currentNumber > ($targetNumber - $hysteresis)
                    : $currentNumber > $targetNumber,
                'below' => $previousCondition === true
                    ? $currentNumber < ($targetNumber + $hysteresis)
                    : $currentNumber < $targetNumber,
                'equals' => abs($currentNumber - $targetNumber)
                    <= max($hysteresis, 0.0000001),
                default => false,
            };

            return [
                'condition' => $condition,
                'trigger' => $condition && $previousCondition !== true,
                'normalized_value' => $this->number($currentNumber),
            ];
        }

        $currentText = $this->normalize($current);
        $targetText = $target !== null
            ? $this->normalize($target)
            : '';
        $previousText = $previous !== null
            ? $this->normalize($previous)
            : null;

        $condition = match ($operator) {
            'equals' => $currentText === $targetText,
            'contains' => $targetText !== ''
                && str_contains($currentText, $targetText),
            'changes' => $previousText !== null
                && $currentText !== $previousText,
            'starts', 'stops' => $targetText !== ''
                && str_contains($currentText, $targetText),
            default => throw new InvalidArgumentException(
                'عملگر شرط پشتیبانی نمی‌شود.'
            ),
        };

        $trigger = match ($operator) {
            'changes' => $condition,
            'stops' => $previousCondition === true && !$condition,
            default => $condition && $previousCondition !== true,
        };

        return [
            'condition' => $condition,
            'trigger' => $trigger,
            'normalized_value' => $currentText,
        ];
    }

    private function normalize(
        int|float|string|bool $value
    ): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_float($value) || is_int($value)) {
            return $this->number((float) $value);
        }

        return mb_strtolower(
            preg_replace(
                '/\s+/u',
                ' ',
                trim($value)
            ) ?? trim($value)
        );
    }

    private function number(float $value): string
    {
        return rtrim(
            rtrim(
                number_format($value, 8, '.', ''),
                '0'
            ),
            '.'
        );
    }
}
