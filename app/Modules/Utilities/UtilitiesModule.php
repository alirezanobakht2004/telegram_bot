<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Utilities;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\ConversationStateStore;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\RateLimiter;
use SmartToolbox\Core\UserPreferenceStore;
use Throwable;

final class UtilitiesModule implements ModuleInterface
{
    private const STATE_SHA256 = 'tools.awaiting_sha256';
    private const STATE_MD5 = 'tools.awaiting_md5';
    private const STATE_BASE64_ENCODE =
        'tools.awaiting_base64_encode';
    private const STATE_BASE64_DECODE =
        'tools.awaiting_base64_decode';
    private const STATE_COUNT = 'tools.awaiting_count';
    private const STATE_RANDOM = 'tools.awaiting_random';

    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly ConversationStateStore $states,
        private readonly UserPreferenceStore $preferences,
        private readonly string $logFile,
        private readonly string $defaultTimezone = 'Asia/Tehran',
        private readonly int $defaultPasswordLength = 20,
        private readonly int $stateTtl = 300,
        private readonly int $maxAttempts = 60,
        private readonly int $windowSeconds = 60,
        private readonly int $maxInputLength = 2500
    ) {
    }

    public function register(CommandRouter $router): void
    {
        $router->command(
            'tools',
            function (MessageContext $context): void {
                $this->showToolsMenu($context);
            }
        );

        $router->command(
            'uuid',
            function (MessageContext $context): void {
                $this->sendUuid($context);
            }
        );

        $router->command(
            'password',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->sendPassword(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'sha256',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleTextCommand(
                    $context,
                    $arguments,
                    self::STATE_SHA256,
                    "متنی را که می‌خواهی SHA-256 شود بفرست. #️⃣"
                );
            }
        );

        $router->command(
            'md5',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleTextCommand(
                    $context,
                    $arguments,
                    self::STATE_MD5,
                    "متنی را که می‌خواهی MD5 شود بفرست. 🧬"
                );
            }
        );

        $router->command(
            'base64',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleTextCommand(
                    $context,
                    $arguments,
                    self::STATE_BASE64_ENCODE,
                    "متن موردنظر برای تبدیل به Base64 را بفرست. 🔤"
                );
            }
        );

        $router->command(
            'base64decode',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleTextCommand(
                    $context,
                    $arguments,
                    self::STATE_BASE64_DECODE,
                    "رشته Base64 را برای Decode بفرست. 🔓"
                );
            }
        );

        $router->command(
            'decode64',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleTextCommand(
                    $context,
                    $arguments,
                    self::STATE_BASE64_DECODE,
                    "رشته Base64 را برای Decode بفرست. 🔓"
                );
            }
        );

        $router->command(
            'count',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleTextCommand(
                    $context,
                    $arguments,
                    self::STATE_COUNT,
                    "متنی را که می‌خواهی شمارش شود بفرست. 📊"
                );
            }
        );

        $router->command(
            'random',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleRandomCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'coin',
            function (MessageContext $context): void {
                $this->sendCoin($context);
            }
        );

        $router->command(
            'timestamp',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->sendTimestamp(
                    $context,
                    $arguments
                );
            }
        );

        /*
         * آخرین ماژولی که /cancel را ثبت می‌کند، Handler عمومی
         * لغو را در Router نگه می‌دارد. این Handler هر State فعالی
         * از تمام ماژول‌ها را پاک می‌کند.
         */
        $router->command(
            'cancel',
            function (MessageContext $context): void {
                $this->cancel($context);
            }
        );

        $router->text(
            '🧰 ابزارها',
            function (MessageContext $context): void {
                $this->showToolsMenu($context);
            }
        );

        $router->text(
            '🔐 رمز تصادفی',
            function (MessageContext $context): void {
                $this->sendPassword($context, '');
            }
        );

        $router->text(
            '🆔 UUID',
            function (MessageContext $context): void {
                $this->sendUuid($context);
            }
        );

        $router->text(
            '#️⃣ هش SHA-256',
            function (MessageContext $context): void {
                $this->askForText(
                    $context,
                    self::STATE_SHA256,
                    "متنی را که می‌خواهی SHA-256 شود بفرست. #️⃣"
                );
            }
        );

        $router->text(
            '🧬 هش MD5',
            function (MessageContext $context): void {
                $this->askForText(
                    $context,
                    self::STATE_MD5,
                    "متنی را که می‌خواهی MD5 شود بفرست. 🧬"
                );
            }
        );

        $router->text(
            '🔤 Base64',
            function (MessageContext $context): void {
                $this->askForText(
                    $context,
                    self::STATE_BASE64_ENCODE,
                    "متن موردنظر برای تبدیل به Base64 را بفرست. 🔤"
                );
            }
        );

        $router->text(
            '🔓 Decode Base64',
            function (MessageContext $context): void {
                $this->askForText(
                    $context,
                    self::STATE_BASE64_DECODE,
                    "رشته Base64 را برای Decode بفرست. 🔓"
                );
            }
        );

        $router->text(
            '📊 شمارش متن',
            function (MessageContext $context): void {
                $this->askForText(
                    $context,
                    self::STATE_COUNT,
                    "متنی را که می‌خواهی شمارش شود بفرست. 📊"
                );
            }
        );

        $router->text(
            '🎲 عدد تصادفی',
            function (MessageContext $context): void {
                $this->askForRandomRange($context);
            }
        );

        $router->text(
            '🪙 شیر یا خط',
            function (MessageContext $context): void {
                $this->sendCoin($context);
            }
        );

        $router->text(
            '🕒 زمان یونیکس',
            function (MessageContext $context): void {
                $this->sendTimestamp($context, '');
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

    private function showToolsMenu(
        MessageContext $context
    ): void {
        $options = [];

        if ($context->isPrivate()) {
            $options['reply_markup'] =
                $this->toolsKeyboard();
        }

        $context->reply(
            "🧰 ابزارهای داخلی و رایگان\n\n"
            . "🔐 تولید رمز امن\n"
            . "🆔 تولید UUID نسخه 4\n"
            . "#️⃣ SHA-256 و MD5\n"
            . "🔤 Encode و Decode Base64\n"
            . "📊 شمارش متن\n"
            . "🎲 عدد تصادفی و شیر یا خط\n"
            . "🕒 تبدیل زمان یونیکس\n\n"
            . "این ابزارها بدون API خارجی و مستقیماً "
            . "روی سرور اجرا می‌شوند.",
            $options
        );
    }

    private function sendUuid(
        MessageContext $context
    ): void {
        if (!$this->allowRequest($context)) {
            return;
        }

        try {
            $bytes = random_bytes(16);

            $bytes[6] = chr(
                (ord($bytes[6]) & 0x0f) | 0x40
            );

            $bytes[8] = chr(
                (ord($bytes[8]) & 0x3f) | 0x80
            );

            $hex = bin2hex($bytes);

            $uuid = sprintf(
                '%s-%s-%s-%s-%s',
                substr($hex, 0, 8),
                substr($hex, 8, 4),
                substr($hex, 12, 4),
                substr($hex, 16, 4),
                substr($hex, 20, 12)
            );

            $context->reply(
                "🆔 UUID v4\n\n"
                . "`{$uuid}`\n\n"
                . "UUID بعدی: /uuid",
                [
                    'parse_mode' => 'Markdown',
                ]
            );
        } catch (Throwable $exception) {
            $this->handleUnexpectedError(
                $context,
                'uuid',
                $exception
            );
        }
    }

    private function sendPassword(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->allowRequest($context)) {
            return;
        }

        try {
            $length = $this->passwordLengthFor(
                $context
            );

            if (trim($arguments) !== '') {
                $normalized = $this->normalizeDigits(
                    trim($arguments)
                );

                if (
                    preg_match(
                        '/^\d+$/',
                        $normalized
                    ) !== 1
                ) {
                    throw new InvalidArgumentException(
                        'طول رمز باید یک عدد صحیح باشد.'
                    );
                }

                $length = (int) $normalized;
            }

            if ($length < 8 || $length > 128) {
                throw new InvalidArgumentException(
                    'طول رمز باید بین 8 تا 128 کاراکتر باشد.'
                );
            }

            $password = $this->generatePassword(
                $length
            );

            $context->reply(
                "🔐 رمز تصادفی امن ({$length} کاراکتر)\n\n"
                . "`{$password}`\n\n"
                . "رمز دیگری با طول دلخواه:\n"
                . "/password 32",
                [
                    'parse_mode' => 'Markdown',
                ]
            );
        } catch (InvalidArgumentException $exception) {
            $context->reply(
                $exception->getMessage()
                . "\n\nنمونه: /password 24"
            );
        } catch (Throwable $exception) {
            $this->handleUnexpectedError(
                $context,
                'password',
                $exception
            );
        }
    }

    private function handleTextCommand(
        MessageContext $context,
        string $arguments,
        string $state,
        string $prompt
    ): void {
        $text = trim($arguments);

        if ($text === '') {
            if ($context->isPrivate()) {
                $this->askForText(
                    $context,
                    $state,
                    $prompt
                );

                return;
            }

            $context->reply(
                "متن را بعد از دستور بنویس.\n\n"
                . "نمونه:\n"
                . "/sha256 hello"
            );

            return;
        }

        $this->states->clear(
            $context->actorKey()
        );

        $this->processTextOperation(
            $context,
            $state,
            $text
        );
    }

    private function askForText(
        MessageContext $context,
        string $state,
        string $prompt
    ): void {
        if (!$context->isPrivate()) {
            $context->reply(
                "در گروه، متن را بعد از دستور بفرست.\n\n"
                . "نمونه: /sha256 hello"
            );

            return;
        }

        $this->states->set(
            $context->actorKey(),
            $state,
            ttlSeconds: $this->stateTtl
        );

        $context->reply(
            $prompt
            . "\n\nبرای لغو: /cancel",
            [
                'reply_markup' => [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' =>
                        'متن موردنظر را بنویس',
                ],
            ]
        );
    }

    private function handleRandomCommand(
        MessageContext $context,
        string $arguments
    ): void {
        $arguments = trim($arguments);

        if ($arguments === '') {
            if ($context->isPrivate()) {
                $this->askForRandomRange($context);

                return;
            }

            $context->reply(
                "حداقل و حداکثر را بعد از دستور وارد کن.\n\n"
                . "نمونه: /random 1 100"
            );

            return;
        }

        $this->states->clear(
            $context->actorKey()
        );

        $this->processRandomRange(
            $context,
            $arguments
        );
    }

    private function askForRandomRange(
        MessageContext $context
    ): void {
        if (!$context->isPrivate()) {
            $context->reply(
                "در گروه، بازه را بعد از دستور بفرست.\n\n"
                . "نمونه: /random 1 100"
            );

            return;
        }

        $this->states->set(
            $context->actorKey(),
            self::STATE_RANDOM,
            ttlSeconds: $this->stateTtl
        );

        $context->reply(
            "حداقل و حداکثر بازه را بفرست. 🎲\n\n"
            . "مثلاً:\n"
            . "1 100\n"
            . "-20 20\n\n"
            . "برای لغو: /cancel",
            [
                'reply_markup' => [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' =>
                        'مثلاً 1 100',
                ],
            ]
        );
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
                'tools.'
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
            === self::STATE_RANDOM
        ) {
            $this->processRandomRange(
                $context,
                $input
            );

            return true;
        }

        $this->processTextOperation(
            $context,
            $state['state'],
            $input
        );

        return true;
    }

    private function processTextOperation(
        MessageContext $context,
        string $state,
        string $input
    ): void {
        if (!$this->allowRequest($context)) {
            return;
        }

        if (mb_strlen($input) > $this->maxInputLength) {
            $context->reply(
                "متن بیش از حد طولانی است.\n\n"
                . "حداکثر طول مجاز: "
                . number_format($this->maxInputLength)
                . ' کاراکتر'
            );

            return;
        }

        try {
            match ($state) {
                self::STATE_SHA256 =>
                    $this->replyHash(
                        $context,
                        'SHA-256',
                        hash('sha256', $input)
                    ),

                self::STATE_MD5 =>
                    $this->replyHash(
                        $context,
                        'MD5',
                        hash('md5', $input)
                    ),

                self::STATE_BASE64_ENCODE =>
                    $this->replyBase64Encoded(
                        $context,
                        $input
                    ),

                self::STATE_BASE64_DECODE =>
                    $this->replyBase64Decoded(
                        $context,
                        $input
                    ),

                self::STATE_COUNT =>
                    $this->replyTextCount(
                        $context,
                        $input
                    ),

                default => throw new InvalidArgumentException(
                    'عملیات ابزار ناشناخته است.'
                ),
            };
        } catch (InvalidArgumentException $exception) {
            $context->reply(
                $exception->getMessage()
            );
        } catch (Throwable $exception) {
            $this->handleUnexpectedError(
                $context,
                $state,
                $exception
            );
        }
    }

    private function replyHash(
        MessageContext $context,
        string $algorithm,
        string $hash
    ): void {
        $warning = $algorithm === 'MD5'
            ? "\n\n⚠️ MD5 برای نگهداری رمز عبور امن نیست."
            : '';

        $context->reply(
            "#️⃣ {$algorithm}\n\n"
            . "`{$hash}`"
            . $warning,
            [
                'parse_mode' => 'Markdown',
            ]
        );
    }

    private function replyBase64Encoded(
        MessageContext $context,
        string $input
    ): void {
        $encoded = base64_encode($input);

        if (strlen($encoded) > 3500) {
            throw new InvalidArgumentException(
                'خروجی Base64 برای ارسال در یک پیام بیش از حد طولانی است.'
            );
        }

        $context->reply(
            "🔤 Base64 Encode\n\n"
            . "`{$encoded}`",
            [
                'parse_mode' => 'Markdown',
            ]
        );
    }

    private function replyBase64Decoded(
        MessageContext $context,
        string $input
    ): void {
        $normalized = preg_replace(
            '/\s+/u',
            '',
            $input
        ) ?? $input;

        $decoded = base64_decode(
            $normalized,
            true
        );

        if ($decoded === false) {
            throw new InvalidArgumentException(
                'رشته واردشده Base64 معتبر نیست.'
            );
        }

        if (!mb_check_encoding($decoded, 'UTF-8')) {
            throw new InvalidArgumentException(
                'خروجی Decode داده باینری است؛ این ابزار فقط متن UTF-8 را نمایش می‌دهد.'
            );
        }

        if (mb_strlen($decoded) > 3500) {
            throw new InvalidArgumentException(
                'خروجی Decode برای ارسال در یک پیام بیش از حد طولانی است.'
            );
        }

        $context->reply(
            "🔓 Base64 Decode\n\n"
            . $decoded
        );
    }

    private function replyTextCount(
        MessageContext $context,
        string $input
    ): void {
        $trimmed = trim($input);

        $words = $trimmed === ''
            ? []
            : preg_split(
                '/\s+/u',
                $trimmed,
                -1,
                PREG_SPLIT_NO_EMPTY
            );

        $wordCount = is_array($words)
            ? count($words)
            : 0;

        $lineCount = $input === ''
            ? 0
            : substr_count(
                str_replace(
                    "\r\n",
                    "\n",
                    $input
                ),
                "\n"
            ) + 1;

        $digits = preg_match_all(
            '/\p{N}/u',
            $input
        );

        $letters = preg_match_all(
            '/\p{L}/u',
            $input
        );

        $context->reply(
            "📊 آمار متن\n\n"
            . "🔤 کاراکترها: "
            . number_format(mb_strlen($input))
            . "\n"
            . "🧱 بایت‌ها: "
            . number_format(strlen($input))
            . "\n"
            . "📝 کلمه‌ها: "
            . number_format($wordCount)
            . "\n"
            . "📄 خط‌ها: "
            . number_format($lineCount)
            . "\n"
            . "🔠 حروف: "
            . number_format(
                is_int($letters)
                    ? $letters
                    : 0
            )
            . "\n"
            . "🔢 ارقام: "
            . number_format(
                is_int($digits)
                    ? $digits
                    : 0
            )
        );
    }

    private function processRandomRange(
        MessageContext $context,
        string $input
    ): void {
        if (!$this->allowRequest($context)) {
            return;
        }

        try {
            $normalized = $this->normalizeDigits(
                trim($input)
            );

            $parts = preg_split(
                '/[\s,،]+/u',
                $normalized,
                -1,
                PREG_SPLIT_NO_EMPTY
            );

            if (
                !is_array($parts)
                || count($parts) !== 2
            ) {
                throw new InvalidArgumentException(
                    'باید دقیقاً دو عدد برای حداقل و حداکثر بفرستی.'
                );
            }

            if (
                preg_match('/^-?\d+$/', $parts[0]) !== 1
                || preg_match('/^-?\d+$/', $parts[1]) !== 1
            ) {
                throw new InvalidArgumentException(
                    'حداقل و حداکثر باید عدد صحیح باشند.'
                );
            }

            $minimum = (int) $parts[0];
            $maximum = (int) $parts[1];

            if ($minimum > $maximum) {
                [$minimum, $maximum] = [
                    $maximum,
                    $minimum,
                ];
            }

            $limit = 1_000_000_000_000;

            if (
                abs($minimum) > $limit
                || abs($maximum) > $limit
            ) {
                throw new InvalidArgumentException(
                    'محدوده اعداد بیش از حد بزرگ است.'
                );
            }

            $number = random_int(
                $minimum,
                $maximum
            );

            $context->reply(
                "🎲 عدد تصادفی\n\n"
                . number_format($number)
                . "\n\n"
                . "بازه: "
                . number_format($minimum)
                . ' تا '
                . number_format($maximum)
            );
        } catch (InvalidArgumentException $exception) {
            $context->reply(
                $exception->getMessage()
                . "\n\nنمونه: /random 1 100"
            );
        } catch (Throwable $exception) {
            $this->handleUnexpectedError(
                $context,
                'random',
                $exception
            );
        }
    }

    private function sendCoin(
        MessageContext $context
    ): void {
        if (!$this->allowRequest($context)) {
            return;
        }

        try {
            $result = random_int(0, 1) === 0
                ? 'شیر 🦁'
                : 'خط ✍️';

            $context->reply(
                "🪙 نتیجه شیر یا خط:\n\n"
                . $result
            );
        } catch (Throwable $exception) {
            $this->handleUnexpectedError(
                $context,
                'coin',
                $exception
            );
        }
    }

    private function sendTimestamp(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->allowRequest($context)) {
            return;
        }

        try {
            $input = trim(
                $this->normalizeDigits($arguments)
            );

            $timezoneName = $this->timezoneFor(
                $context
            );

            $timezone = new DateTimeZone(
                $timezoneName
            );

            if ($input === '') {
                $date = new DateTimeImmutable(
                    'now',
                    $timezone
                );
            } elseif (
                preg_match('/^-?\d+$/', $input) === 1
            ) {
                $date = (
                    new DateTimeImmutable('@' . $input)
                )->setTimezone($timezone);
            } else {
                $date = new DateTimeImmutable(
                    $input,
                    $timezone
                );
            }

            $utc = $date->setTimezone(
                new DateTimeZone('UTC')
            );

            $context->reply(
                "🕒 تبدیل زمان\n\n"
                . "Unix timestamp:\n"
                . "`{$date->getTimestamp()}`\n\n"
                . "زمان {$timezoneName}:\n"
                . $date->format('Y-m-d H:i:s P')
                . "\n\nUTC:\n"
                . $utc->format('Y-m-d H:i:s \U\T\C')
                . "\n\nنمونه تبدیل:\n"
                . "/timestamp 1783886786\n"
                . "/timestamp 2026-07-12 23:00",
                [
                    'parse_mode' => 'Markdown',
                ]
            );
        } catch (Throwable $exception) {
            $context->reply(
                "فرمت زمان قابل تشخیص نیست. ⚠️\n\n"
                . "نمونه‌ها:\n"
                . "/timestamp\n"
                . "/timestamp 1783886786\n"
                . "/timestamp 2026-07-12 23:00"
            );

            $this->log(
                'timestamp',
                $exception
            );
        }
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

    private function allowRequest(
        MessageContext $context
    ): bool {
        $rateLimit = $this->rateLimiter->attempt(
            'tools:' . $context->actorKey(),
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

    private function passwordLengthFor(
        MessageContext $context
    ): int {
        $fallback = max(
            8,
            min(128, $this->defaultPasswordLength)
        );

        $stored = $this->preferences->get(
            $context->actorKey(),
            'password_length'
        );

        if (
            $stored === null
            || preg_match('/^\d+$/', $stored) !== 1
        ) {
            return $fallback;
        }

        $length = (int) $stored;

        return $length >= 8 && $length <= 128
            ? $length
            : $fallback;
    }

    private function timezoneFor(
        MessageContext $context
    ): string {
        $stored = $this->preferences->get(
            $context->actorKey(),
            'timezone'
        );

        $timezone = $stored !== null
            ? trim($stored)
            : trim($this->defaultTimezone);

        if ($timezone === '') {
            $timezone = 'Asia/Tehran';
        }

        try {
            new DateTimeZone($timezone);

            return $timezone;
        } catch (Throwable) {
            return 'Asia/Tehran';
        }
    }

    private function generatePassword(
        int $length
    ): string {
        $groups = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnopqrstuvwxyz',
            '23456789',
            '!@#$%^&*()-_=+[]{}',
        ];

        $characters = [];

        foreach ($groups as $group) {
            $characters[] = $group[
                random_int(
                    0,
                    strlen($group) - 1
                )
            ];
        }

        $pool = implode('', $groups);

        while (count($characters) < $length) {
            $characters[] = $pool[
                random_int(
                    0,
                    strlen($pool) - 1
                )
            ];
        }

        for (
            $index = count($characters) - 1;
            $index > 0;
            $index--
        ) {
            $swapIndex = random_int(
                0,
                $index
            );

            [
                $characters[$index],
                $characters[$swapIndex],
            ] = [
                $characters[$swapIndex],
                $characters[$index],
            ];
        }

        return implode('', $characters);
    }

    /**
     * @return array<string, mixed>
     */
    private function toolsKeyboard(): array
    {
        return [
            'keyboard' => [
                [
                    [
                        'text' => '🔐 رمز تصادفی',
                    ],
                    [
                        'text' => '🆔 UUID',
                    ],
                ],
                [
                    [
                        'text' => '#️⃣ هش SHA-256',
                    ],
                    [
                        'text' => '🧬 هش MD5',
                    ],
                ],
                [
                    [
                        'text' => '🔤 Base64',
                    ],
                    [
                        'text' => '🔓 Decode Base64',
                    ],
                ],
                [
                    [
                        'text' => '📊 شمارش متن',
                    ],
                    [
                        'text' => '🎲 عدد تصادفی',
                    ],
                ],
                [
                    [
                        'text' => '🪙 شیر یا خط',
                    ],
                    [
                        'text' => '🕒 زمان یونیکس',
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
                'یکی از ابزارهای داخلی را انتخاب کن',
        ];
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

    private function handleUnexpectedError(
        MessageContext $context,
        string $operation,
        Throwable $exception
    ): void {
        $this->log(
            $operation,
            $exception
        );

        $context->reply(
            "اجرای این ابزار با خطا مواجه شد. ⚠️\n\n"
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
            "[%s] [operation:%s] %s\n",
            date(DATE_ATOM),
            str_replace(
                ["\r", "\n"],
                ' ',
                mb_substr($operation, 0, 100)
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
