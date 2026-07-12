<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Reminders;

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

final class ReminderModule implements ModuleInterface
{
    private const STATE_AWAITING_REMINDER =
        'reminders.awaiting_input';

    public function __construct(
        private readonly ReminderRepository $repository,
        private readonly ReminderTimeParser $parser,
        private readonly RateLimiter $rateLimiter,
        private readonly ConversationStateStore $states,
        private readonly UserPreferenceStore $preferences,
        private readonly string $logFile,
        private readonly string $defaultTimezone =
            'Asia/Tehran',
        private readonly int $stateTtl = 300,
        private readonly int $maxAttempts = 30,
        private readonly int $windowSeconds = 60,
        private readonly int $maxTextLength = 1000,
        private readonly int $maxPendingPerUser = 50,
        private readonly int $maxFutureDays = 365
    ) {
    }

    public function register(
        CommandRouter $router
    ): void {
        $router->command(
            'remind',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleCreateCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'reminder',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->handleCreateCommand(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'reminders',
            function (
                MessageContext $context
            ): void {
                $this->listActive($context);
            }
        );

        $router->command(
            'reminderhistory',
            function (
                MessageContext $context
            ): void {
                $this->showHistory($context);
            }
        );

        $router->command(
            'remindercancel',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->cancelReminder(
                    $context,
                    $arguments
                );
            }
        );

        $router->command(
            'reminderdelete',
            function (
                MessageContext $context,
                string $arguments
            ): void {
                $this->deleteReminder(
                    $context,
                    $arguments
                );
            }
        );

        $router->text(
            '⏰ یادآورها',
            function (
                MessageContext $context
            ): void {
                $this->showMenu($context);
            }
        );

        $router->text(
            '➕ افزودن یادآور',
            function (
                MessageContext $context
            ): void {
                $this->askForReminder(
                    $context
                );
            }
        );

        $router->text(
            '📋 یادآورهای من',
            function (
                MessageContext $context
            ): void {
                $this->listActive($context);
            }
        );

        $router->text(
            '🕘 تاریخچه یادآورها',
            function (
                MessageContext $context
            ): void {
                $this->showHistory($context);
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

    private function showMenu(
        MessageContext $context
    ): void {
        $options = [];

        if ($context->isPrivate()) {
            $options['reply_markup'] =
                $this->keyboard();
        }

        $context->reply(
            "⏰ یادآورها\n\n"
            . "یک یادآور یک‌باره بساز، "
            . "فهرست فعال‌ها را ببین یا "
            . "تاریخچه را بررسی کن.\n\n"
            . "نمونه‌ها:\n"
            . "/remind 10m خرید شیر\n"
            . "/remind فردا 09:00 جلسه\n"
            . "/remind 2026-07-15 18:30 تماس\n\n"
            . "تاریخ‌های عددی میلادی هستند.",
            $options
        );
    }

    private function handleCreateCommand(
        MessageContext $context,
        string $arguments
    ): void {
        $input = trim($arguments);

        if ($input === '') {
            if ($context->isPrivate()) {
                $this->askForReminder(
                    $context
                );

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

        $this->createReminder(
            $context,
            $input
        );
    }

    private function askForReminder(
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
            self::STATE_AWAITING_REMINDER,
            ttlSeconds: $this->safeStateTtl()
        );

        $context->reply(
            "زمان و متن یادآور را در یک پیام بفرست. ⏰\n\n"
            . "نمونه‌ها:\n"
            . "10m خرید شیر\n"
            . "2 ساعت تماس با علی\n"
            . "فردا 09:00 جلسه\n"
            . "امروز 18:30 ورزش\n"
            . "2026-07-15 18:30 پرداخت قبض\n\n"
            . "تاریخ عددی میلادی است.\n"
            . "برای لغو: /cancel",
            [
                'reply_markup' => [
                    'force_reply' => true,
                    'selective' => true,
                    'input_field_placeholder' =>
                        'مثلاً 10m خرید شیر',
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
            || $state['state']
                !== self::STATE_AWAITING_REMINDER
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

        $this->createReminder(
            $context,
            $input
        );

        return true;
    }

    private function createReminder(
        MessageContext $context,
        string $input
    ): void {
        $userId = $context->userId;

        if ($userId === null || $userId <= 0) {
            $context->reply(
                'برای ساخت یادآور، شناسه کاربر تلگرام لازم است.'
            );

            return;
        }

        if (!$this->allowRequest($context)) {
            return;
        }

        try {
            $activeCount =
                $this->repository
                    ->countActiveForUser(
                        $userId
                    );

            $maximum =
                $this->safeMaxPending();

            if ($activeCount >= $maximum) {
                $context->reply(
                    "به سقف {$maximum} یادآور فعال رسیدی.\n\n"
                    . "ابتدا یکی را لغو کن:\n"
                    . "/reminders"
                );

                return;
            }

            $timezone =
                $this->timezoneFor($context);

            $parsed = $this->parser->parse(
                $input,
                $timezone,
                $this->safeMaxFutureDays()
            );

            if (
                mb_strlen($parsed['text'])
                > $this->safeMaxTextLength()
            ) {
                throw new InvalidArgumentException(
                    "متن یادآور نباید بیشتر از "
                    . $this->safeMaxTextLength()
                    . ' کاراکتر باشد.'
                );
            }

            $id = $this->repository->create(
                userId: $userId,
                chatId: $context->chatId,
                text: $parsed['text'],
                scheduledAt:
                    $parsed['scheduled_at'],
                timezone:
                    $parsed['timezone']
            );

            $context->reply(
                "یادآور ساخته شد. ✅\n\n"
                . "🆔 #{$id}\n"
                . "🗓 "
                . $parsed['display_time']
                . "\n"
                . "🌐 "
                . $parsed['timezone']
                . "\n"
                . "📝 "
                . $parsed['text']
                . "\n\n"
                . "فهرست: /reminders\n"
                . "لغو: /remindercancel {$id}"
            );
        } catch (InvalidArgumentException $exception) {
            $context->reply(
                "یادآور ساخته نشد. ⚠️\n\n"
                . $exception->getMessage()
                . "\n\n"
                . $this->usageText()
            );
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'create',
                $exception
            );
        }
    }

    private function listActive(
        MessageContext $context
    ): void {
        if (!$context->isPrivate()) {
            $context->reply(
                'برای حفظ حریم خصوصی، فهرست یادآورها را در چت خصوصی ربات ببین.'
            );

            return;
        }

        $userId = $context->userId;

        if ($userId === null) {
            return;
        }

        try {
            $rows =
                $this->repository
                    ->activeForUser(
                        $userId,
                        20
                    );

            if ($rows === []) {
                $context->reply(
                    "یادآور فعالی نداری. ⏰\n\n"
                    . "ساخت یادآور:\n"
                    . "/remind 10m خرید شیر"
                );

                return;
            }

            $message =
                "📋 یادآورهای فعال\n\n";

            foreach ($rows as $row) {
                $date = $this->formatTime(
                    (int) $row['scheduled_at'],
                    (string) $row['timezone']
                );

                $message .= "#"
                    . (int) $row['id']
                    . " — {$date}\n"
                    . (string) $row[
                        'reminder_text'
                    ]
                    . "\n"
                    . "لغو: /remindercancel "
                    . (int) $row['id']
                    . "\n\n";
            }

            $context->reply(
                trim($message)
            );
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'list',
                $exception
            );
        }
    }

    private function showHistory(
        MessageContext $context
    ): void {
        if (!$context->isPrivate()) {
            $context->reply(
                'تاریخچه یادآورها فقط در چت خصوصی نمایش داده می‌شود.'
            );

            return;
        }

        $userId = $context->userId;

        if ($userId === null) {
            return;
        }

        try {
            $rows =
                $this->repository
                    ->historyForUser(
                        $userId,
                        15
                    );

            if ($rows === []) {
                $context->reply(
                    'هنوز سابقه‌ای برای یادآورها وجود ندارد.'
                );

                return;
            }

            $message =
                "🕘 تاریخچه یادآورها\n\n";

            foreach ($rows as $row) {
                $status = $this->statusLabel(
                    (string) $row['status']
                );

                $message .= "#"
                    . (int) $row['id']
                    . " — {$status}\n"
                    . $this->formatTime(
                        (int) $row[
                            'scheduled_at'
                        ],
                        (string) $row[
                            'timezone'
                        ]
                    )
                    . "\n"
                    . (string) $row[
                        'reminder_text'
                    ]
                    . "\n";

                if (
                    in_array(
                        $row['status'],
                        [
                            'sent',
                            'failed',
                            'cancelled',
                        ],
                        true
                    )
                ) {
                    $message .=
                        "حذف: /reminderdelete "
                        . (int) $row['id']
                        . "\n";
                }

                $message .= "\n";
            }

            $context->reply(
                trim($message)
            );
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'history',
                $exception
            );
        }
    }

    private function cancelReminder(
        MessageContext $context,
        string $arguments
    ): void {
        $userId = $context->userId;

        if ($userId === null) {
            return;
        }

        $id = $this->parseId($arguments);

        if ($id === null) {
            $context->reply(
                "شناسه یادآور معتبر نیست.\n\n"
                . "نمونه: /remindercancel 12"
            );

            return;
        }

        try {
            $cancelled =
                $this->repository
                    ->cancelForUser(
                        $userId,
                        $id
                    );

            $context->reply(
                $cancelled
                    ? "یادآور #{$id} لغو شد. ✅"
                    : 'یادآور فعال یا ناموفق با این شناسه پیدا نشد.'
            );
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'cancel',
                $exception
            );
        }
    }

    private function deleteReminder(
        MessageContext $context,
        string $arguments
    ): void {
        $userId = $context->userId;

        if ($userId === null) {
            return;
        }

        $id = $this->parseId($arguments);

        if ($id === null) {
            $context->reply(
                "شناسه یادآور معتبر نیست.\n\n"
                . "نمونه: /reminderdelete 12"
            );

            return;
        }

        try {
            $deleted =
                $this->repository
                    ->deleteForUser(
                        $userId,
                        $id
                    );

            $context->reply(
                $deleted
                    ? "یادآور #{$id} حذف شد. ✅"
                    : 'فقط یادآورهای ارسال‌شده، لغوشده یا ناموفق قابل حذف‌اند.'
            );
        } catch (Throwable $exception) {
            $this->handleError(
                $context,
                'delete',
                $exception
            );
        }
    }

    private function allowRequest(
        MessageContext $context
    ): bool {
        $result = $this->rateLimiter->attempt(
            'reminders:'
            . $context->actorKey(),
            max(1, $this->maxAttempts),
            max(1, $this->windowSeconds)
        );

        if ($result->allowed) {
            return true;
        }

        $context->reply(
            "درخواست‌های زیادی فرستادی. ⏳\n\n"
            . "حدود {$result->retryAfter} ثانیه دیگر "
            . 'دوباره امتحان کن.'
        );

        return false;
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

    private function formatTime(
        int $timestamp,
        string $timezone
    ): string {
        try {
            return (
                new DateTimeImmutable(
                    '@' . $timestamp
                )
            )->setTimezone(
                new DateTimeZone($timezone)
            )->format(
                'Y-m-d H:i'
            );
        } catch (Throwable) {
            return date(
                'Y-m-d H:i',
                $timestamp
            );
        }
    }

    private function parseId(
        string $value
    ): ?int {
        $value = $this->normalizeDigits(
            trim($value)
        );

        if (
            preg_match(
                '/^\d+$/',
                $value
            ) !== 1
        ) {
            return null;
        }

        $id = (int) $value;

        return $id > 0
            ? $id
            : null;
    }

    private function statusLabel(
        string $status
    ): string {
        return match ($status) {
            'pending' => '⏳ در صف',
            'processing' => '📨 در حال پردازش',
            'sent' => '✅ ارسال‌شده',
            'failed' => '⚠️ ناموفق',
            'cancelled' => '🛑 لغوشده',
            default => $status,
        };
    }

    private function usageText(): string
    {
        return "فرمت ساخت یادآور:\n\n"
            . "/remind 10m خرید شیر\n"
            . "/remind 2 ساعت تماس\n"
            . "/remind فردا 09:00 جلسه\n"
            . "/remind امروز 18:30 ورزش\n"
            . "/remind 2026-07-15 18:30 پرداخت قبض\n\n"
            . "واحدهای نسبی: دقیقه، ساعت، روز و هفته\n"
            . "تاریخ‌های عددی میلادی هستند.";
    }

    /**
     * @return array<string, mixed>
     */
    private function keyboard(): array
    {
        return [
            'keyboard' => [
                [
                    [
                        'text' => '➕ افزودن یادآور',
                    ],
                ],
                [
                    [
                        'text' => '📋 یادآورهای من',
                    ],
                    [
                        'text' =>
                            '🕘 تاریخچه یادآورها',
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
                'یادآور جدید یا فهرست',
        ];
    }

    private function safeStateTtl(): int
    {
        return max(
            30,
            min(86400, $this->stateTtl)
        );
    }

    private function safeMaxTextLength(): int
    {
        return max(
            50,
            min(3000, $this->maxTextLength)
        );
    }

    private function safeMaxPending(): int
    {
        return max(
            1,
            min(500, $this->maxPendingPerUser)
        );
    }

    private function safeMaxFutureDays(): int
    {
        return max(
            1,
            min(3650, $this->maxFutureDays)
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
            "عملیات یادآور با خطا مواجه شد. ⚠️\n\n"
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
                mb_substr(
                    $operation,
                    0,
                    100
                )
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
