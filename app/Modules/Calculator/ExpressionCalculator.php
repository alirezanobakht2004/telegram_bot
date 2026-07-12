<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Calculator;

use InvalidArgumentException;

final class ExpressionCalculator
{
    private string $expression = '';

    private int $position = 0;

    private int $length = 0;

    public function evaluate(string $expression): float
    {
        $this->expression = $this->normalize(
            $expression
        );

        $this->position = 0;
        $this->length = strlen(
            $this->expression
        );

        if ($this->length === 0) {
            throw new InvalidArgumentException(
                'عبارت محاسباتی خالی است.'
            );
        }

        if (
            preg_match(
                '/[^0-9A-Za-z_+\-*\/%^().\s]/',
                $this->expression
            ) === 1
        ) {
            throw new InvalidArgumentException(
                'عبارت شامل کاراکتر غیرمجاز است.'
            );
        }

        $result = $this->parseExpression();

        $this->skipWhitespace();

        if ($this->position !== $this->length) {
            throw new InvalidArgumentException(
                'ساختار عبارت محاسباتی معتبر نیست.'
            );
        }

        return $this->ensureFinite($result);
    }

    private function parseExpression(): float
    {
        $value = $this->parseTerm();

        while (true) {
            $this->skipWhitespace();

            if ($this->consume('+')) {
                $value += $this->parseTerm();
                $value = $this->ensureFinite($value);

                continue;
            }

            if ($this->consume('-')) {
                $value -= $this->parseTerm();
                $value = $this->ensureFinite($value);

                continue;
            }

            return $value;
        }
    }

    private function parseTerm(): float
    {
        $value = $this->parsePower();

        while (true) {
            $this->skipWhitespace();

            if ($this->consume('*')) {
                $value *= $this->parsePower();
                $value = $this->ensureFinite($value);

                continue;
            }

            if ($this->consume('/')) {
                $divisor = $this->parsePower();

                if ($divisor == 0.0) {
                    throw new InvalidArgumentException(
                        'تقسیم بر صفر مجاز نیست.'
                    );
                }

                $value /= $divisor;
                $value = $this->ensureFinite($value);

                continue;
            }

            if ($this->consume('%')) {
                $divisor = $this->parsePower();

                if ($divisor == 0.0) {
                    throw new InvalidArgumentException(
                        'باقی‌مانده تقسیم بر صفر مجاز نیست.'
                    );
                }

                $value = fmod($value, $divisor);
                $value = $this->ensureFinite($value);

                continue;
            }

            return $value;
        }
    }

    private function parsePower(): float
    {
        $base = $this->parseUnary();

        $this->skipWhitespace();

        if (!$this->consume('^')) {
            return $base;
        }

        /*
         * فراخوانی بازگشتی باعث می‌شود توان‌ها از راست
         * ارزیابی شوند: 2^3^2 برابر 2^(3^2) است.
         */
        $exponent = $this->parsePower();

        $result = $base ** $exponent;

        return $this->ensureFinite($result);
    }

    private function parseUnary(): float
    {
        $this->skipWhitespace();

        if ($this->consume('+')) {
            return $this->parseUnary();
        }

        if ($this->consume('-')) {
            return -$this->parseUnary();
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): float
    {
        $this->skipWhitespace();

        if ($this->consume('(')) {
            $value = $this->parseExpression();

            $this->skipWhitespace();

            if (!$this->consume(')')) {
                throw new InvalidArgumentException(
                    'پرانتز بسته در عبارت وجود ندارد.'
                );
            }

            return $value;
        }

        $number = $this->readNumber();

        if ($number !== null) {
            return $number;
        }

        $identifier = $this->readIdentifier();

        if ($identifier === null) {
            throw new InvalidArgumentException(
                'عدد، تابع یا پرانتز معتبر انتظار می‌رفت.'
            );
        }

        $identifier = strtolower($identifier);

        if ($identifier === 'pi') {
            return M_PI;
        }

        if ($identifier === 'e') {
            return M_E;
        }

        $this->skipWhitespace();

        if (!$this->consume('(')) {
            throw new InvalidArgumentException(
                "تابع {$identifier} باید با پرانتز استفاده شود."
            );
        }

        $argument = $this->parseExpression();

        $this->skipWhitespace();

        if (!$this->consume(')')) {
            throw new InvalidArgumentException(
                "پرانتز تابع {$identifier} بسته نشده است."
            );
        }

        return $this->applyFunction(
            $identifier,
            $argument
        );
    }

    private function readNumber(): ?float
    {
        $remaining = substr(
            $this->expression,
            $this->position
        );

        $matched = preg_match(
            '/^(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+\-]?\d+)?/',
            $remaining,
            $matches
        );

        if ($matched !== 1) {
            return null;
        }

        $token = $matches[0];

        $this->position += strlen($token);

        $value = (float) $token;

        return $this->ensureFinite($value);
    }

    private function readIdentifier(): ?string
    {
        $remaining = substr(
            $this->expression,
            $this->position
        );

        $matched = preg_match(
            '/^[A-Za-z_]+/',
            $remaining,
            $matches
        );

        if ($matched !== 1) {
            return null;
        }

        $identifier = $matches[0];

        $this->position += strlen($identifier);

        return $identifier;
    }

    private function applyFunction(
        string $function,
        float $argument
    ): float {
        $result = match ($function) {
            'sqrt' => $argument >= 0
                ? sqrt($argument)
                : throw new InvalidArgumentException(
                    'ریشه دوم عدد منفی در اعداد حقیقی تعریف نشده است.'
                ),

            'abs' => abs($argument),
            'round' => round($argument),
            'floor' => floor($argument),
            'ceil' => ceil($argument),
            'sin' => sin($argument),
            'cos' => cos($argument),
            'tan' => tan($argument),

            'ln' => $argument > 0
                ? log($argument)
                : throw new InvalidArgumentException(
                    'ورودی تابع ln باید بزرگ‌تر از صفر باشد.'
                ),

            'log', 'log10' => $argument > 0
                ? log10($argument)
                : throw new InvalidArgumentException(
                    'ورودی تابع log باید بزرگ‌تر از صفر باشد.'
                ),

            default => throw new InvalidArgumentException(
                "تابع «{$function}» پشتیبانی نمی‌شود."
            ),
        };

        return $this->ensureFinite($result);
    }

    private function consume(string $character): bool
    {
        if (
            $this->position >= $this->length
            || $this->expression[
                $this->position
            ] !== $character
        ) {
            return false;
        }

        $this->position++;

        return true;
    }

    private function skipWhitespace(): void
    {
        while (
            $this->position < $this->length
            && ctype_space(
                $this->expression[
                    $this->position
                ]
            )
        ) {
            $this->position++;
        }
    }

    private function ensureFinite(float $value): float
    {
        if (!is_finite($value)) {
            throw new InvalidArgumentException(
                'نتیجه محاسبه بیش از حد بزرگ یا تعریف‌نشده است.'
            );
        }

        return $value;
    }

    private function normalize(string $expression): string
    {
        $expression = strtr(
            trim($expression),
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

                '٫' => '.',
                '٬' => '',
                ',' => '',
                '×' => '*',
                '✕' => '*',
                '÷' => '/',
                '−' => '-',
                '–' => '-',
                '—' => '-',
                '٪' => '%',
            ]
        );

        return $expression;
    }
}
