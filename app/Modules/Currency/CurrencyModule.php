<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Currency;

use InvalidArgumentException;
use RuntimeException;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\ConversationStateStore;
use SmartToolbox\Core\FileCache;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\RateLimiter;
use Throwable;

final class CurrencyModule implements ModuleInterface
{
    private const STATE_AWAITING_CONVERSION =
        'currency.awaiting_conversion';

    public function __construct(
        private readonly CurrencyProviderInterface $provider,
        private readonly FileCache $cache,
        private readonly RateLimiter $rateLimiter,
        private readonly ConversationStateStore $states,
        private readonly string $logFile,
        private readonly int $rateCacheTtl = 3600,
        private readonly int $stateTtl = 300,
        private readonly int $maxAttempts = 30,
        private readonly int $windowSeconds = 60
    ) {
    }

    public function register(CommandRouter $router): void
    {
        $router->command(
            'currency',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'rate',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'currencies',
            function (MessageContext $context): void {
                $this->showCurrencies($context);
            }
        );

        /*
         * این Handler عمداً عمومی است؛ اگر کاربر در هر ماژولی
         * وضعیت مرحله‌ای فعالی داشته باشد، /cancel آن را پاک می‌کند.
         */
        $router->command(
            'cancel',
            function (MessageContext $context): void {
                $this->cancel($context);
            }
        );

        $router->text(
            '💱 نرخ ارز',
            function (MessageContext $context): void {
                $this->askForConversion($context);
            }
        );

        $router->fallbackText(
            function (
                MessageContext $context,
                string $text
            ): bool {
                return $this->handlePendingConversion(
                    $context,
                    $text
                );
            }
        );
    }

    private function handleCommand(
        MessageContext $context,
        string $arguments
    ): void {
        $arguments = trim($arguments);

        if ($arguments === '') {
            if ($context->isPrivate()) {
                $this->askForConversion($context);

                return;
            }

            $context->reply(
                $this->usageText()
            );

            return;
        }

        $this->states->clear(
            $context->actorKey()
        );

        $this->convertAndReply(
            $context,
            $arguments
        );
    }

    private function askForConversion(
        MessageContext $context
    ): void {
        if (!$context->isPrivate()) {
            $context->reply(
                $this->usageText()
            );

            return;
        }

        $this->states->set(
            $context->actorKey(),
            self::STATE_AWAITING_CONVERSION,
            ttlSeconds: $this->stateTtl
        );

        $context->reply(
            "مقدار، ارز مبدأ و ارز مقصد را بفرست. 💱\n\n"
            . "نمونه‌ها:\n"
            . "100 USD EUR\n"
            . "1 دلار یورو\n"
            . "USD GBP\n\n"
            . "برای نرخ ریال رسمی از IRR استفاده کن.\n"
            . "برای لغو: /cancel",
            [
                'reply_markup' => [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' =>
                        'مثلاً 100 USD EUR',
                ],
            ]
        );
    }

    private function handlePendingConversion(
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
            || $state['state']
                !== self::STATE_AWAITING_CONVERSION
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

        $this->convertAndReply(
            $context,
            $input
        );

        return true;
    }

    private function convertAndReply(
        MessageContext $context,
        string $input
    ): void {
        try {
            $request = $this->parseInput($input);
        } catch (InvalidArgumentException $exception) {
            $context->reply(
                $exception->getMessage()
                . "\n\n"
                . $this->usageText()
            );

            return;
        }

        $rateLimit = $this->rateLimiter->attempt(
            'currency:' . $context->actorKey(),
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
            $quote = $this->getQuote(
                $request['base'],
                $request['quote']
            );

            $converted = $request['amount']
                * $quote['rate'];

            if (!is_finite($converted)) {
                throw new RuntimeException(
                    'Converted currency amount is too large.'
                );
            }

            $context->reply(
                $this->formatResult(
                    amount: $request['amount'],
                    base: $quote['base'],
                    quote: $quote['quote'],
                    rate: $quote['rate'],
                    converted: $converted,
                    date: $quote['date']
                )
            );
        } catch (Throwable $exception) {
            $this->log(
                $input,
                $exception
            );

            $context->reply(
                "فعلاً دریافت نرخ این جفت‌ارز ممکن نشد. ⚠️\n\n"
                . "کد ارزها را بررسی کن و چند لحظه بعد "
                . "دوباره امتحان کن.\n\n"
                . "برای نمونه: /currency 100 USD EUR"
            );
        }
    }

    /**
     * @return array{
     *     amount: float,
     *     base: string,
     *     quote: string
     * }
     */
    private function parseInput(string $input): array
    {
        $input = $this->normalizeDigits(
            trim($input)
        );

        $input = str_ireplace(
            [
                'دلار آمریکا',
                'دلار امريکا',
                'دلار کانادا',
                'دلار استرالیا',
                'پوند انگلیس',
                'پوند بريتانيا',
                'ریال ایران',
                'ريال ايران',
                'درهم امارات',
                'دینار عراق',
                'دينار عراق',
                'دینار کویت',
                'دينار کويت',
                'فرانک سوئیس',
                'فرانک سوئيس',
            ],
            [
                'USD',
                'USD',
                'CAD',
                'AUD',
                'GBP',
                'GBP',
                'IRR',
                'IRR',
                'AED',
                'IQD',
                'IQD',
                'KWD',
                'KWD',
                'CHF',
                'CHF',
            ],
            $input
        );

        $input = str_ireplace(
            [
                ' to ',
                ' به ',
                '->',
                '=>',
                '/',
                '\\',
            ],
            ' ',
            $input
        );

        $input = preg_replace(
            '/\s+/u',
            ' ',
            trim($input)
        ) ?? $input;

        $parts = preg_split(
            '/\s+/u',
            $input,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if (!is_array($parts)) {
            throw new InvalidArgumentException(
                'ورودی تبدیل ارز نامعتبر است.'
            );
        }

        if (count($parts) === 2) {
            $amount = 1.0;
            [$baseToken, $quoteToken] = $parts;
        } elseif (count($parts) === 3) {
            $amountToken = str_replace(
                [
                    ',',
                    '٬',
                    ' ',
                ],
                '',
                $parts[0]
            );

            $amountToken = str_replace(
                '٫',
                '.',
                $amountToken
            );

            if (!is_numeric($amountToken)) {
                throw new InvalidArgumentException(
                    'مقدار ارز باید عدد باشد.'
                );
            }

            $amount = (float) $amountToken;
            $baseToken = $parts[1];
            $quoteToken = $parts[2];
        } else {
            throw new InvalidArgumentException(
                'فرمت ورودی را درست وارد نکردی.'
            );
        }

        if (
            $amount <= 0
            || !is_finite($amount)
        ) {
            throw new InvalidArgumentException(
                'مقدار ارز باید عددی بزرگ‌تر از صفر باشد.'
            );
        }

        if ($amount > 1_000_000_000_000_000) {
            throw new InvalidArgumentException(
                'مقدار واردشده بیش از حد بزرگ است.'
            );
        }

        return [
            'amount' => $amount,
            'base' => $this->normalizeCurrency(
                $baseToken
            ),
            'quote' => $this->normalizeCurrency(
                $quoteToken
            ),
        ];
    }

    private function normalizeCurrency(
        string $currency
    ): string {
        $currency = mb_strtolower(
            trim($currency)
        );

        if (
            in_array(
                $currency,
                [
                    'تومان',
                    'تومن',
                    'irt',
                    'toman',
                ],
                true
            )
        ) {
            throw new InvalidArgumentException(
                "منبع رایگان و قابل‌اعتماد برای نرخ لحظه‌ای "
                . "بازار آزاد تومان استفاده نمی‌کنیم.\n"
                . "برای نرخ مرجع رسمی ریال، کد IRR را بفرست."
            );
        }

        $aliases = [
            'دلار' => 'USD',
            'usd' => 'USD',
            'dollar' => 'USD',

            'یورو' => 'EUR',
            'يورو' => 'EUR',
            'eur' => 'EUR',
            'euro' => 'EUR',

            'پوند' => 'GBP',
            'gbp' => 'GBP',
            'pound' => 'GBP',

            'ین' => 'JPY',
            'ين' => 'JPY',
            'jpy' => 'JPY',
            'yen' => 'JPY',

            'یوان' => 'CNY',
            'يوان' => 'CNY',
            'cny' => 'CNY',
            'yuan' => 'CNY',

            'لیر' => 'TRY',
            'لير' => 'TRY',
            'try' => 'TRY',
            'lira' => 'TRY',

            'درهم' => 'AED',
            'aed' => 'AED',
            'dirham' => 'AED',

            'ریال' => 'IRR',
            'ريال' => 'IRR',
            'irr' => 'IRR',
            'rial' => 'IRR',

            'روبل' => 'RUB',
            'rub' => 'RUB',
            'ruble' => 'RUB',

            'فرانک' => 'CHF',
            'chf' => 'CHF',
            'franc' => 'CHF',

            'دلارکانادا' => 'CAD',
            'cad' => 'CAD',

            'دلاراسترالیا' => 'AUD',
            'دلاراستراليا' => 'AUD',
            'aud' => 'AUD',

            'افغانی' => 'AFN',
            'افغاني' => 'AFN',
            'afn' => 'AFN',

            'دینارعراق' => 'IQD',
            'دينارعراق' => 'IQD',
            'iqd' => 'IQD',

            'دینارکویت' => 'KWD',
            'دينارکويت' => 'KWD',
            'kwd' => 'KWD',

            'روپیه' => 'INR',
            'روپيه' => 'INR',
            'inr' => 'INR',
        ];

        $code = $aliases[$currency]
            ?? mb_strtoupper($currency);

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException(
                "کد ارز «{$currency}» معتبر نیست. "
                . "از کد سه‌حرفی مثل USD یا EUR استفاده کن."
            );
        }

        return $code;
    }

    /**
     * @return array{
     *     base: string,
     *     quote: string,
     *     rate: float,
     *     date: string
     * }
     */
    private function getQuote(
        string $base,
        string $quote
    ): array {
        $cacheKey = sprintf(
            'currency.rate.%s.%s',
            $base,
            $quote
        );

        $result = $this->cache->remember(
            $cacheKey,
            $this->rateCacheTtl,
            fn (): array => $this->provider->quote(
                $base,
                $quote
            )
        );

        if (
            !is_array($result)
            || !is_numeric($result['rate'] ?? null)
            || !is_string($result['base'] ?? null)
            || !is_string($result['quote'] ?? null)
            || !is_string($result['date'] ?? null)
        ) {
            throw new RuntimeException(
                'Cached currency rate is invalid.'
            );
        }

        return [
            'base' => mb_strtoupper($result['base']),
            'quote' => mb_strtoupper($result['quote']),
            'rate' => (float) $result['rate'],
            'date' => $result['date'],
        ];
    }

    private function formatResult(
        float $amount,
        string $base,
        string $quote,
        float $rate,
        float $converted,
        string $date
    ): string {
        $baseLabel = $this->currencyLabel($base);
        $quoteLabel = $this->currencyLabel($quote);

        $message = "💱 تبدیل ارز\n\n"
            . $this->formatNumber($amount)
            . " {$base} ({$baseLabel})"
            . "\n=\n"
            . $this->formatNumber($converted)
            . " {$quote} ({$quoteLabel})"
            . "\n\n"
            . "نرخ مرجع:\n"
            . "1 {$base} = "
            . $this->formatNumber($rate)
            . " {$quote}";

        if ($rate > 0) {
            $message .= "\n"
                . "1 {$quote} = "
                . $this->formatNumber(1 / $rate)
                . " {$base}";
        }

        if ($date !== '') {
            $message .= "\n📅 تاریخ نرخ: {$date}";
        }

        if ($base === 'IRR' || $quote === 'IRR') {
            $message .= "\n\n"
                . "⚠️ نرخ IRR مرجع/رسمی است و نرخ بازار "
                . "آزاد تومان ایران نیست.";
        }

        $message .= "\n\n"
            . "منبع: Frankfurter\n"
            . "این داده برای اطلاع‌رسانی است، نه انجام معامله.";

        return $message;
    }

    private function formatNumber(float $value): string
    {
        $absolute = abs($value);

        $decimals = match (true) {
            $absolute >= 1000 => 2,
            $absolute >= 1 => 4,
            $absolute >= 0.01 => 6,
            default => 8,
        };

        $formatted = number_format(
            $value,
            $decimals,
            '.',
            ','
        );

        if (str_contains($formatted, '.')) {
            $formatted = rtrim(
                rtrim($formatted, '0'),
                '.'
            );
        }

        return $formatted;
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

    private function currencyLabel(
        string $code
    ): string {
        return match ($code) {
            'USD' => 'دلار آمریکا',
            'EUR' => 'یورو',
            'GBP' => 'پوند بریتانیا',
            'JPY' => 'ین ژاپن',
            'CNY' => 'یوان چین',
            'TRY' => 'لیر ترکیه',
            'AED' => 'درهم امارات',
            'IRR' => 'ریال ایران',
            'RUB' => 'روبل روسیه',
            'CHF' => 'فرانک سوئیس',
            'CAD' => 'دلار کانادا',
            'AUD' => 'دلار استرالیا',
            'AFN' => 'افغانی افغانستان',
            'IQD' => 'دینار عراق',
            'KWD' => 'دینار کویت',
            'INR' => 'روپیه هند',
            'SAR' => 'ریال عربستان',
            'QAR' => 'ریال قطر',
            default => $code,
        };
    }

    private function showCurrencies(
        MessageContext $context
    ): void {
        $context->reply(
            "💱 چند ارز پرکاربرد\n\n"
            . "USD — دلار آمریکا\n"
            . "EUR — یورو\n"
            . "GBP — پوند بریتانیا\n"
            . "JPY — ین ژاپن\n"
            . "CNY — یوان چین\n"
            . "TRY — لیر ترکیه\n"
            . "AED — درهم امارات\n"
            . "IRR — ریال رسمی ایران\n"
            . "CHF — فرانک سوئیس\n"
            . "CAD — دلار کانادا\n"
            . "AUD — دلار استرالیا\n"
            . "AFN — افغانی\n"
            . "IQD — دینار عراق\n"
            . "KWD — دینار کویت\n"
            . "INR — روپیه هند\n\n"
            . "نمونه:\n"
            . "/currency 100 USD EUR\n\n"
            . "سرویس از کدهای سه‌حرفی ISO بیشتری هم "
            . "پشتیبانی می‌کند."
        );
    }

    private function usageText(): string
    {
        return "فرمت استفاده از تبدیل ارز:\n\n"
            . "/currency 100 USD EUR\n"
            . "/currency USD GBP\n"
            . "/currency 1 دلار یورو\n\n"
            . "عدد اول اختیاری است و اگر ارسال نشود، "
            . "مقدار 1 در نظر گرفته می‌شود.\n\n"
            . "فهرست نمونه ارزها: /currencies";
    }

    private function cancel(
        MessageContext $context
    ): void {
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

    private function log(
        string $input,
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
            "[%s] [input:%s] %s\n",
            date(DATE_ATOM),
            str_replace(
                ["\r", "\n"],
                ' ',
                mb_substr($input, 0, 150)
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
