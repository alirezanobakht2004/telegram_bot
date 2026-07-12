<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Calculator;

use InvalidArgumentException;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\ConversationStateStore;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\RateLimiter;
use Throwable;

final class CalculatorModule implements ModuleInterface
{
    private const STATE_EXPRESSION =
        'calculator.awaiting_expression';

    private const STATE_CONVERSION =
        'calculator.awaiting_conversion';

    public function __construct(
        private readonly ExpressionCalculator $calculator,
        private readonly UnitConverter $converter,
        private readonly RateLimiter $rateLimiter,
        private readonly ConversationStateStore $states,
        private readonly string $logFile,
        private readonly int $stateTtl = 300,
        private readonly int $maxAttempts = 60,
        private readonly int $windowSeconds = 60,
        private readonly int $maxExpressionLength = 500,
        private readonly int $maxConversionLength = 200
    ) {
    }

    public function register(CommandRouter $router): void
    {
        $router->command(
            'calc',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleCalculationCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'calculate',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleCalculationCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'convert',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleConversionCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'unit',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleConversionCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'units',
            function (MessageContext $context): void {
                $this->showUnits($context);
            }
        );

        $router->text(
            '🧮 ماشین حساب',
            function (MessageContext $context): void {
                $this->showCalculatorMenu($context);
            }
        );

        $router->text(
            '➗ محاسبه عبارت',
            function (MessageContext $context): void {
                $this->askForExpression($context);
            }
        );

        $router->text(
            '📏 تبدیل واحد',
            function (MessageContext $context): void {
                $this->askForConversion($context);
            }
        );

        $router->text(
            '📚 واحدهای پشتیبانی‌شده',
            function (MessageContext $context): void {
                $this->showUnits($context);
            }
        );

        $router->fallbackText(
            function (
                MessageContext $context,
                string $text
            ): bool {
                return $this->handlePendingInput(
                    $context,
                    $text
                );
            }
        );
    }

    private function showCalculatorMenu(
        MessageContext $context
    ): void {
        $options = [];

        if ($context->isPrivate()) {
            $options['reply_markup'] =
                $this->calculatorKeyboard();
        }

        $context->reply(
            "🧮 ماشین حساب و تبدیل واحد\n\n"
            . "➗ محاسبه عبارت‌های ریاضی امن\n"
            . "📏 تبدیل واحدهای پرکاربرد\n"
            . "📚 مشاهده فهرست واحدها\n\n"
            . "نمونه محاسبه:\n"
            . "/calc 2*(3+4)\n"
            . "/calc sqrt(81)+abs(-4)\n\n"
            . "نمونه تبدیل:\n"
            . "/convert 10 km mi\n"
            . "/convert 32 F C\n\n"
            . "هیچ عبارت PHP اجرا نمی‌شود و "
            . "از eval استفاده نشده است.",
            $options
        );
    }

    private function handleCalculationCommand(
        MessageContext $context,
        string $arguments
    ): void {
        $expression = trim($arguments);

        if ($expression === '') {
            if ($context->isPrivate()) {
                $this->askForExpression($context);

                return;
            }

            $context->reply(
                $this->calculationUsage()
            );

            return;
        }

        $this->states->clear(
            $context->actorKey()
        );

        $this->calculateAndReply(
            $context,
            $expression
        );
    }

    private function askForExpression(
        MessageContext $context
    ): void {
        if (!$context->isPrivate()) {
            $context->reply(
                $this->calculationUsage()
            );

            return;
        }

        $this->states->set(
            $context->actorKey(),
            self::STATE_EXPRESSION,
            ttlSeconds: $this->stateTtl
        );

        $context->reply(
            "عبارت ریاضی را بفرست. ➗\n\n"
            . "عملگرها:\n"
            . "+  -  *  /  %  ^  ( )\n\n"
            . "توابع:\n"
            . "sqrt, abs, round, floor, ceil,\n"
            . "sin, cos, tan, ln, log\n\n"
            . "ثابت‌ها: pi و e\n\n"
            . "نمونه:\n"
            . "2*(3+4)\n"
            . "sqrt(81)+abs(-4)\n\n"
            . "برای لغو: /cancel",
            [
                'reply_markup' => [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' =>
                        'مثلاً 2*(3+4)',
                ],
            ]
        );
    }

    private function calculateAndReply(
        MessageContext $context,
        string $expression
    ): void {
        if (!$this->allowRequest($context)) {
            return;
        }

        if (
            mb_strlen($expression)
            > $this->safeMaxExpressionLength()
        ) {
            $context->reply(
                "عبارت بیش از حد طولانی است.\n\n"
                . "حداکثر طول مجاز: "
                . number_format(
                    $this->safeMaxExpressionLength()
                )
                . ' کاراکتر'
            );

            return;
        }

        try {
            $result = $this->calculator->evaluate(
                $expression
            );

            $context->reply(
                "🧮 نتیجه محاسبه\n\n"
                . trim($expression)
                . "\n=\n"
                . $this->formatNumber($result)
                . "\n\n"
                . "توابع مثلثاتی با رادیان محاسبه می‌شوند."
            );
        } catch (InvalidArgumentException $exception) {
            $context->reply(
                "عبارت معتبر نیست. ⚠️\n\n"
                . $exception->getMessage()
                . "\n\n"
                . $this->calculationUsage()
            );
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'calculation',
                $exception
            );
        }
    }

    private function handleConversionCommand(
        MessageContext $context,
        string $arguments
    ): void {
        $input = trim($arguments);

        if ($input === '') {
            if ($context->isPrivate()) {
                $this->askForConversion($context);

                return;
            }

            $context->reply(
                $this->conversionUsage()
            );

            return;
        }

        $this->states->clear(
            $context->actorKey()
        );

        $this->convertAndReply(
            $context,
            $input
        );
    }

    private function askForConversion(
        MessageContext $context
    ): void {
        if (!$context->isPrivate()) {
            $context->reply(
                $this->conversionUsage()
            );

            return;
        }

        $this->states->set(
            $context->actorKey(),
            self::STATE_CONVERSION,
            ttlSeconds: $this->stateTtl
        );

        $context->reply(
            "مقدار، واحد مبدأ و واحد مقصد را بفرست. 📏\n\n"
            . "نمونه‌ها:\n"
            . "10 km mi\n"
            . "32 F C\n"
            . "1 GiB MiB\n"
            . "2 ساعت دقیقه\n\n"
            . "برای لغو: /cancel",
            [
                'reply_markup' => [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' =>
                        'مثلاً 10 km mi',
                ],
            ]
        );
    }

    private function convertAndReply(
        MessageContext $context,
        string $input
    ): void {
        if (!$this->allowRequest($context)) {
            return;
        }

        if (
            mb_strlen($input)
            > $this->safeMaxConversionLength()
        ) {
            $context->reply(
                "ورودی تبدیل بیش از حد طولانی است.\n\n"
                . "حداکثر طول مجاز: "
                . number_format(
                    $this->safeMaxConversionLength()
                )
                . ' کاراکتر'
            );

            return;
        }

        try {
            $request = $this->parseConversionInput(
                $input
            );

            $result = $this->converter->convert(
                $request['amount'],
                $request['from'],
                $request['to']
            );

            $context->reply(
                "📏 تبدیل واحد\n\n"
                . $this->formatNumber(
                    $result['amount']
                )
                . ' '
                . $result['from_symbol']
                . ' ('
                . $result['from_label']
                . ")\n=\n"
                . $this->formatNumber(
                    $result['result']
                )
                . ' '
                . $result['to_symbol']
                . ' ('
                . $result['to_label']
                . ")\n\n"
                . "دسته: "
                . $result['category_label']
            );
        } catch (InvalidArgumentException $exception) {
            $context->reply(
                "تبدیل انجام نشد. ⚠️\n\n"
                . $exception->getMessage()
                . "\n\n"
                . $this->conversionUsage()
            );
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'conversion',
                $exception
            );
        }
    }

    /**
     * @return array{
     *     amount: float,
     *     from: string,
     *     to: string
     * }
     */
    private function parseConversionInput(
        string $input
    ): array {
        $input = trim(
            $this->normalizeDigits($input)
        );

        $input = str_replace(
            [
                '→',
                '=>',
                '->',
            ],
            ' به ',
            $input
        );

        $input = preg_replace(
            '/\s+/u',
            ' ',
            $input
        ) ?? $input;

        $parts = preg_split(
            '/\s+(?:to|به)\s+/iu',
            $input,
            2
        );

        if (
            is_array($parts)
            && count($parts) === 2
        ) {
            $leftParts = preg_split(
                '/\s+/u',
                trim($parts[0]),
                2,
                PREG_SPLIT_NO_EMPTY
            );

            if (
                !is_array($leftParts)
                || count($leftParts) !== 2
            ) {
                throw new InvalidArgumentException(
                    'مقدار و واحد مبدأ مشخص نیست.'
                );
            }

            $amountToken = $leftParts[0];
            $from = $leftParts[1];
            $to = trim($parts[1]);
        } else {
            $tokens = preg_split(
                '/\s+/u',
                $input,
                -1,
                PREG_SPLIT_NO_EMPTY
            );

            if (
                !is_array($tokens)
                || count($tokens) !== 3
            ) {
                throw new InvalidArgumentException(
                    'فرمت تبدیل باید شامل مقدار، واحد مبدأ و واحد مقصد باشد.'
                );
            }

            [
                $amountToken,
                $from,
                $to,
            ] = $tokens;
        }

        $amountToken = str_replace(
            [
                ',',
                '٬',
            ],
            '',
            $amountToken
        );

        $amountToken = str_replace(
            '٫',
            '.',
            $amountToken
        );

        if (
            preg_match(
                '/^[+\-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+\-]?\d+)?$/',
                $amountToken
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'مقدار تبدیل باید عدد معتبر باشد.'
            );
        }

        $amount = (float) $amountToken;

        if (!is_finite($amount)) {
            throw new InvalidArgumentException(
                'مقدار تبدیل بیش از حد بزرگ است.'
            );
        }

        if (trim($from) === '' || trim($to) === '') {
            throw new InvalidArgumentException(
                'واحد مبدأ و مقصد باید مشخص باشند.'
            );
        }

        return [
            'amount' => $amount,
            'from' => trim($from),
            'to' => trim($to),
        ];
    }

    private function handlePendingInput(
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
            || !str_starts_with(
                $state['state'],
                'calculator.'
            )
        ) {
            return false;
        }

        $input = trim($text);

        if ($input === '') {
            return true;
        }

        $this->states->clear(
            $context->actorKey()
        );

        if (
            $state['state']
            === self::STATE_EXPRESSION
        ) {
            $this->calculateAndReply(
                $context,
                $input
            );

            return true;
        }

        if (
            $state['state']
            === self::STATE_CONVERSION
        ) {
            $this->convertAndReply(
                $context,
                $input
            );

            return true;
        }

        return false;
    }

    private function showUnits(
        MessageContext $context
    ): void {
        if (!$this->allowRequest($context)) {
            return;
        }

        $context->reply(
            $this->converter->supportedUnitsText()
        );
    }

    private function allowRequest(
        MessageContext $context
    ): bool {
        $rateLimit = $this->rateLimiter->attempt(
            'calculator:' . $context->actorKey(),
            $this->maxAttempts,
            $this->windowSeconds
        );

        if ($rateLimit->allowed) {
            return true;
        }

        $context->reply(
            "درخواست‌های زیادی فرستادی. ⏳\n\n"
            . "حدود {$rateLimit->retryAfter} ثانیه دیگر "
            . 'دوباره امتحان کن.'
        );

        return false;
    }

    private function calculationUsage(): string
    {
        return "فرمت ماشین حساب:\n\n"
            . "/calc 2*(3+4)\n"
            . "/calc sqrt(81)+abs(-4)\n"
            . "/calc 2^8\n\n"
            . "عملگرها: + - * / % ^ و پرانتز\n"
            . "توابع: sqrt, abs, round, floor, ceil, "
            . "sin, cos, tan, ln, log\n"
            . "ثابت‌ها: pi و e";
    }

    private function conversionUsage(): string
    {
        return "فرمت تبدیل واحد:\n\n"
            . "/convert 10 km mi\n"
            . "/convert 32 F C\n"
            . "/convert 1 GiB MiB\n"
            . "/convert 2 ساعت دقیقه\n\n"
            . "فهرست واحدها: /units";
    }

    private function formatNumber(
        float $value
    ): string {
        if ($value == 0.0) {
            return '0';
        }

        $absolute = abs($value);

        if (
            $absolute >= 1_000_000_000_000
            || $absolute < 0.00000001
        ) {
            return sprintf('%.12g', $value);
        }

        $formatted = number_format(
            $value,
            12,
            '.',
            ','
        );

        return rtrim(
            rtrim($formatted, '0'),
            '.'
        );
    }

    private function safeMaxExpressionLength(): int
    {
        return max(
            20,
            min(1000, $this->maxExpressionLength)
        );
    }

    private function safeMaxConversionLength(): int
    {
        return max(
            20,
            min(500, $this->maxConversionLength)
        );
    }

    private function normalizeDigits(
        string $value
    ): string {
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
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function calculatorKeyboard(): array
    {
        return [
            'keyboard' => [
                [
                    [
                        'text' => '➗ محاسبه عبارت',
                    ],
                    [
                        'text' => '📏 تبدیل واحد',
                    ],
                ],
                [
                    [
                        'text' =>
                            '📚 واحدهای پشتیبانی‌شده',
                    ],
                ],
                [
                    [
                        'text' => '🏠 منوی اصلی',
                    ],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
            'input_field_placeholder' =>
                'ماشین حساب یا تبدیل واحد',
        ];
    }

    private function handleError(
        MessageContext $context,
        string $operation,
        Throwable $exception
    ): void {
        $this->log(
            $operation,
            $exception
        );

        $context->reply(
            "اجرای ماشین حساب با خطا مواجه شد. ⚠️\n\n"
            . 'چند لحظه بعد دوباره امتحان کن.'
        );
    }

    private function log(
        string $operation,
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
            "[%s] [operation:%s] %s\n%s\n\n",
            date(DATE_ATOM),
            str_replace(
                ["\r", "\n"],
                ' ',
                mb_substr($operation, 0, 100)
            ),
            $exception->getMessage(),
            $exception->getTraceAsString()
        );

        @file_put_contents(
            $this->logFile,
            $entry,
            FILE_APPEND | LOCK_EX
        );
    }
}
