<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Settings;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\ConversationStateStore;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\UserPreferenceStore;
use Throwable;

final class SettingsModule implements ModuleInterface
{
    private const STATE_TIMEZONE =
        'settings.awaiting_timezone';

    private const STATE_PASSWORD_LENGTH =
        'settings.awaiting_password_length';

    public function __construct(
        private readonly UserPreferenceStore $preferences,
        private readonly ConversationStateStore $states,
        private readonly string $logFile,
        private readonly string $defaultTimezone =
            'Asia/Tehran',
        private readonly int $defaultPasswordLength = 20,
        private readonly int $stateTtl = 300
    ) {
    }

    public function register(CommandRouter $router): void
    {
        $router->command(
            'settings',
            function (MessageContext $context): void {
                $this->showSettings($context);
            }
        );

        $router->command(
            'settimezone',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleTimezoneCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'setpasswordlength',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handlePasswordLengthCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'resetsettings',
            function (MessageContext $context): void {
                $this->resetSettings($context);
            }
        );

        $router->text(
            '⚙️ تنظیمات',
            function (MessageContext $context): void {
                $this->showSettings($context);
            }
        );

        $router->text(
            '🌐 منطقه زمانی',
            function (MessageContext $context): void {
                $this->askForTimezone($context);
            }
        );

        $router->text(
            '🔢 طول پیش‌فرض رمز',
            function (MessageContext $context): void {
                $this->askForPasswordLength($context);
            }
        );

        $router->text(
            '♻️ بازنشانی تنظیمات',
            function (MessageContext $context): void {
                $this->resetSettings($context);
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

    private function showSettings(
        MessageContext $context
    ): void {
        $timezone = $this->timezoneFor(
            $context
        );

        $passwordLength =
            $this->passwordLengthFor($context);

        $now = new DateTimeImmutable(
            'now',
            new DateTimeZone($timezone)
        );

        $options = [];

        if ($context->isPrivate()) {
            $options['reply_markup'] =
                $this->settingsKeyboard();
        }

        $context->reply(
            "⚙️ تنظیمات شخصی\n\n"
            . "🌐 منطقه زمانی: {$timezone}\n"
            . "🕒 ساعت فعلی: "
            . $now->format('Y-m-d H:i:s')
            . "\n"
            . "🔐 طول پیش‌فرض رمز: "
            . "{$passwordLength} کاراکتر\n\n"
            . "این تنظیمات برای حساب تلگرام تو "
            . "در SQLite ربات ذخیره می‌شوند.\n"
            . "هیچ سرویس پولی یا API خارجی استفاده نمی‌شود.",
            $options
        );
    }

    private function handleTimezoneCommand(
        MessageContext $context,
        string $arguments
    ): void {
        $timezone = trim($arguments);

        if ($timezone === '') {
            if ($context->isPrivate()) {
                $this->askForTimezone($context);

                return;
            }

            $context->reply(
                $this->timezoneUsage()
            );

            return;
        }

        $this->states->clear(
            $context->actorKey()
        );

        $this->saveTimezone(
            $context,
            $timezone
        );
    }

    private function askForTimezone(
        MessageContext $context
    ): void {
        if (!$context->isPrivate()) {
            $context->reply(
                $this->timezoneUsage()
            );

            return;
        }

        $this->states->set(
            $context->actorKey(),
            self::STATE_TIMEZONE,
            ttlSeconds: $this->stateTtl
        );

        $context->reply(
            "منطقه زمانی را بفرست. 🌐\n\n"
            . "نمونه‌ها:\n"
            . "Asia/Tehran\n"
            . "UTC\n"
            . "Europe/London\n"
            . "Asia/Dubai\n\n"
            . "نام‌های فارسی مثل تهران، لندن، دبی و "
            . "استانبول هم پشتیبانی می‌شوند.\n\n"
            . "برای لغو: /cancel",
            [
                'reply_markup' => [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' =>
                        'مثلاً Asia/Tehran',
                ],
            ]
        );
    }

    private function saveTimezone(
        MessageContext $context,
        string $input
    ): void {
        try {
            $timezone = $this->normalizeTimezone(
                $input
            );

            $this->preferences->set(
                $context->actorKey(),
                'timezone',
                $timezone
            );

            $now = new DateTimeImmutable(
                'now',
                new DateTimeZone($timezone)
            );

            $context->reply(
                "منطقه زمانی ذخیره شد. ✅\n\n"
                . "🌐 {$timezone}\n"
                . "🕒 "
                . $now->format('Y-m-d H:i:s')
                . "\n\n"
                . "دستور /timestamp از این تنظیم "
                . "استفاده می‌کند."
            );
        } catch (InvalidArgumentException $exception) {
            $context->reply(
                $exception->getMessage()
                . "\n\n"
                . $this->timezoneUsage()
            );
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'timezone',
                $exception
            );
        }
    }

    private function handlePasswordLengthCommand(
        MessageContext $context,
        string $arguments
    ): void {
        $length = trim($arguments);

        if ($length === '') {
            if ($context->isPrivate()) {
                $this->askForPasswordLength(
                    $context
                );

                return;
            }

            $context->reply(
                "طول رمز را بعد از دستور وارد کن.\n\n"
                . "نمونه: /setpasswordlength 24"
            );

            return;
        }

        $this->states->clear(
            $context->actorKey()
        );

        $this->savePasswordLength(
            $context,
            $length
        );
    }

    private function askForPasswordLength(
        MessageContext $context
    ): void {
        if (!$context->isPrivate()) {
            $context->reply(
                "در گروه طول رمز را بعد از دستور وارد کن.\n\n"
                . "نمونه: /setpasswordlength 24"
            );

            return;
        }

        $this->states->set(
            $context->actorKey(),
            self::STATE_PASSWORD_LENGTH,
            ttlSeconds: $this->stateTtl
        );

        $context->reply(
            "طول پیش‌فرض رمز تصادفی را بفرست. 🔢\n\n"
            . "عدد باید بین 8 تا 128 باشد.\n"
            . "مثلاً: 24\n\n"
            . "برای لغو: /cancel",
            [
                'reply_markup' => [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' =>
                        'مثلاً 24',
                ],
            ]
        );
    }

    private function savePasswordLength(
        MessageContext $context,
        string $input
    ): void {
        try {
            $normalized = $this->normalizeDigits(
                trim($input)
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

            if ($length < 8 || $length > 128) {
                throw new InvalidArgumentException(
                    'طول رمز باید بین 8 تا 128 باشد.'
                );
            }

            $this->preferences->set(
                $context->actorKey(),
                'password_length',
                (string) $length
            );

            $context->reply(
                "طول پیش‌فرض رمز ذخیره شد. ✅\n\n"
                . "🔐 {$length} کاراکتر\n\n"
                . "از این پس /password بدون عدد، "
                . "رمزی با همین طول تولید می‌کند."
            );
        } catch (InvalidArgumentException $exception) {
            $context->reply(
                $exception->getMessage()
                . "\n\nنمونه: /setpasswordlength 24"
            );
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'password-length',
                $exception
            );
        }
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
                'settings.'
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
            === self::STATE_TIMEZONE
        ) {
            $this->saveTimezone(
                $context,
                $input
            );

            return true;
        }

        if (
            $state['state']
            === self::STATE_PASSWORD_LENGTH
        ) {
            $this->savePasswordLength(
                $context,
                $input
            );

            return true;
        }

        return false;
    }

    private function resetSettings(
        MessageContext $context
    ): void {
        try {
            $deleted = $this->preferences->clear(
                $context->actorKey()
            );

            $this->states->clear(
                $context->actorKey()
            );

            $context->reply(
                "تنظیمات بازنشانی شد. ✅\n\n"
                . "🌐 منطقه زمانی پیش‌فرض: "
                . "{$this->safeDefaultTimezone()}\n"
                . "🔐 طول پیش‌فرض رمز: "
                . $this->safeDefaultPasswordLength()
                . "\n\n"
                . "تعداد تنظیمات حذف‌شده: {$deleted}"
            );
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'reset',
                $exception
            );
        }
    }

    private function normalizeTimezone(
        string $input
    ): string {
        $input = trim(
            strtr(
                $input,
                [
                    'ي' => 'ی',
                    'ك' => 'ک',
                ]
            )
        );

        if (mb_strlen($input) > 100) {
            throw new InvalidArgumentException(
                'نام منطقه زمانی بیش از حد طولانی است.'
            );
        }

        $aliases = [
            'تهران' => 'Asia/Tehran',
            'ایران' => 'Asia/Tehran',
            'utc' => 'UTC',
            'گرینویچ' => 'UTC',
            'لندن' => 'Europe/London',
            'استانبول' => 'Europe/Istanbul',
            'دبی' => 'Asia/Dubai',
            'دوحه' => 'Asia/Qatar',
            'ریاض' => 'Asia/Riyadh',
            'کابل' => 'Asia/Kabul',
            'باکو' => 'Asia/Baku',
            'تفلیس' => 'Asia/Tbilisi',
            'ایروان' => 'Asia/Yerevan',
            'مسکو' => 'Europe/Moscow',
            'برلین' => 'Europe/Berlin',
            'پاریس' => 'Europe/Paris',
            'توکیو' => 'Asia/Tokyo',
            'پکن' => 'Asia/Shanghai',
            'دهلی' => 'Asia/Kolkata',
            'نیویورک' => 'America/New_York',
            'لس آنجلس' => 'America/Los_Angeles',
            'تورنتو' => 'America/Toronto',
            'سیدنی' => 'Australia/Sydney',
        ];

        $timezone = $aliases[
            mb_strtolower($input)
        ] ?? $input;

        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new InvalidArgumentException(
                "منطقه زمانی «{$input}» معتبر نیست."
            );
        }

        return $timezone;
    }

    private function timezoneFor(
        MessageContext $context
    ): string {
        $stored = $this->preferences->get(
            $context->actorKey(),
            'timezone'
        );

        if ($stored !== null) {
            try {
                new DateTimeZone($stored);

                return $stored;
            } catch (Throwable) {
                $this->preferences->delete(
                    $context->actorKey(),
                    'timezone'
                );
            }
        }

        return $this->safeDefaultTimezone();
    }

    private function passwordLengthFor(
        MessageContext $context
    ): int {
        $stored = $this->preferences->get(
            $context->actorKey(),
            'password_length'
        );

        if (
            $stored !== null
            && preg_match('/^\d+$/', $stored) === 1
        ) {
            $length = (int) $stored;

            if ($length >= 8 && $length <= 128) {
                return $length;
            }

            $this->preferences->delete(
                $context->actorKey(),
                'password_length'
            );
        }

        return $this->safeDefaultPasswordLength();
    }

    private function safeDefaultTimezone(): string
    {
        try {
            new DateTimeZone(
                $this->defaultTimezone
            );

            return $this->defaultTimezone;
        } catch (Throwable) {
            return 'Asia/Tehran';
        }
    }

    private function safeDefaultPasswordLength(): int
    {
        return max(
            8,
            min(128, $this->defaultPasswordLength)
        );
    }

    private function timezoneUsage(): string
    {
        return "فرمت تنظیم منطقه زمانی:\n\n"
            . "/settimezone Asia/Tehran\n"
            . "/settimezone UTC\n"
            . "/settimezone Europe/London\n\n"
            . "فهرست رسمی IANA استفاده می‌شود.";
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsKeyboard(): array
    {
        return [
            'keyboard' => [
                [
                    [
                        'text' => '🌐 منطقه زمانی',
                    ],
                ],
                [
                    [
                        'text' =>
                            '🔢 طول پیش‌فرض رمز',
                    ],
                ],
                [
                    [
                        'text' =>
                            '♻️ بازنشانی تنظیمات',
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
                'یکی از تنظیمات را انتخاب کن',
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
            "ذخیره تنظیمات با خطا مواجه شد. ⚠️\n\n"
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
