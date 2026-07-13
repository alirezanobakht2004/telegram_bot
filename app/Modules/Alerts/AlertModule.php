<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Alerts;

use InvalidArgumentException;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\RateLimiter;
use SmartToolbox\Core\UserPreferenceStore;
use Throwable;

final class AlertModule implements ModuleInterface
{
    private const OPERATORS = [
        'above',
        'below',
        'equals',
        'changes',
        'contains',
        'starts',
        'stops',
    ];

    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly SubscriptionRepository $subscriptions,
        private readonly AlertDataProvider $data,
        private readonly ScheduleCalculator $schedule,
        private readonly UserPreferenceStore $preferences,
        private readonly RateLimiter $rateLimiter,
        private readonly string $defaultTimezone = 'Asia/Tehran',
        private readonly int $maxAlertsPerUser = 30,
        private readonly int $maxSubscriptionsPerUser = 20,
        private readonly int $defaultCheckIntervalSeconds = 300,
        private readonly int $defaultCooldownSeconds = 3600,
        private readonly float $defaultHysteresis = 0.5,
        private readonly int $maxNotificationsPerDay = 3,
        private readonly int $maxAttempts = 40,
        private readonly int $windowSeconds = 60,
        private readonly bool $alertsEnabled = true,
        private readonly bool $subscriptionsEnabled = true
    ) {
    }

    public function register(CommandRouter $router): void
    {
        if ($this->alertsEnabled) {
            $router->command(
                'alert',
                fn (MessageContext $context, string $arguments): mixed =>
                    $this->createAlert($context, $arguments),
                'alerts'
            );
            $router->command(
                'alerts',
                fn (MessageContext $context): mixed =>
                    $this->listAlerts($context),
                'alerts'
            );
            $router->command(
                'alertcancel',
                fn (MessageContext $context, string $arguments): mixed =>
                    $this->changeAlert($context, $arguments, 'cancel'),
                'alerts'
            );
            $router->command(
                'alertpause',
                fn (MessageContext $context, string $arguments): mixed =>
                    $this->changeAlert($context, $arguments, 'pause'),
                'alerts'
            );
            $router->command(
                'alertresume',
                fn (MessageContext $context, string $arguments): mixed =>
                    $this->changeAlert($context, $arguments, 'resume'),
                'alerts'
            );
        }

        if ($this->subscriptionsEnabled) {
            $router->command(
                'subscribe',
                fn (MessageContext $context, string $arguments): mixed =>
                    $this->createSubscription($context, $arguments),
                'alerts'
            );
            $router->command(
                'subscriptions',
                fn (MessageContext $context): mixed =>
                    $this->listSubscriptions($context),
                'alerts'
            );
            $router->command(
                'subscriptioncancel',
                fn (MessageContext $context, string $arguments): mixed =>
                    $this->changeSubscription($context, $arguments, 'cancel'),
                'alerts'
            );
            $router->command(
                'subscriptionpause',
                fn (MessageContext $context, string $arguments): mixed =>
                    $this->changeSubscription($context, $arguments, 'pause'),
                'alerts'
            );
            $router->command(
                'subscriptionresume',
                fn (MessageContext $context, string $arguments): mixed =>
                    $this->changeSubscription($context, $arguments, 'resume'),
                'alerts'
            );
        }

        if ($this->alertsEnabled || $this->subscriptionsEnabled) {
            $router->text(
                '🔔 هشدارها',
                fn (MessageContext $context): mixed =>
                    $this->menu($context),
                'alerts'
            );
        }
    }

    private function menu(MessageContext $context): void
    {
        $context->reply(
            "🔔 هشدارها و اشتراک‌ها\n\n"
            . "/alert weather Tehran rain\n"
            . "/alert temperature Tehran below 0\n"
            . "/alert wind Tehran above 60\n"
            . "/alert currency USD EUR above 0.90\n\n"
            . "/subscribe weather Tehran daily 08:00\n"
            . "/subscribe weather Tehran weekly saturday 08:00\n"
            . "/subscribe country Iran monthly 1 09:00\n\n"
            . "/alerts\n/subscriptions"
        );
    }

    private function createAlert(
        MessageContext $context,
        string $arguments
    ): void {
        $userId = $this->privateUser($context);

        if ($userId === null || !$this->allow($context)) {
            return;
        }

        if (
            $this->alerts->countActiveForUser($userId)
            >= max(1, $this->maxAlertsPerUser)
        ) {
            $context->reply(
                'به سقف هشدارهای فعال رسیده‌ای. ابتدا یک هشدار را لغو کن.'
            );
            return;
        }

        try {
            $parsed = $this->parseAlert($arguments);
            $this->data->observation($parsed);
            $id = $this->alerts->create([
                'user_id' => $userId,
                'chat_id' => $context->chatId,
                ...$parsed,
                'cooldown_seconds' => max(0, $this->defaultCooldownSeconds),
                'hysteresis' => max(0.0, $this->defaultHysteresis),
                'max_notifications_per_day' => max(1, $this->maxNotificationsPerDay),
                'check_interval_seconds' => max(60, $this->defaultCheckIntervalSeconds),
                'next_check_at' => time(),
            ]);

            $context->reply(
                "هشدار ساخته شد. ✅\n\n"
                . "🆔 #{$id}\n"
                . $this->alertDescription($parsed)
                . "\n\nلغو: /alertcancel {$id}\n"
                . "توقف: /alertpause {$id}"
            );
        } catch (InvalidArgumentException $exception) {
            $context->reply(
                "هشدار ساخته نشد. ⚠️\n\n"
                . $exception->getMessage()
                . "\n\n"
                . $this->alertUsage()
            );
        } catch (Throwable $exception) {
            $context->reply(
                'ساخت هشدار با خطای موقت مواجه شد.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAlert(string $input): array
    {
        $input = $this->normalizeDigits(
            preg_replace('/\s+/u', ' ', trim($input)) ?? trim($input)
        );
        $tokens = preg_split('/\s+/u', $input, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($tokens) || count($tokens) < 3) {
            throw new InvalidArgumentException(
                'پارامترهای هشدار کامل نیستند.'
            );
        }

        $type = mb_strtolower((string) array_shift($tokens));

        if ($type === 'currency') {
            if (count($tokens) < 3) {
                throw new InvalidArgumentException(
                    'فرمت هشدار ارز کامل نیست.'
                );
            }

            $base = mb_strtoupper((string) array_shift($tokens));
            $quote = mb_strtoupper((string) array_shift($tokens));
            $operator = mb_strtolower((string) array_shift($tokens));
            $value = $tokens[0] ?? null;

            if (preg_match('/^[A-Z]{3}$/', $base) !== 1
                || preg_match('/^[A-Z]{3}$/', $quote) !== 1
            ) {
                throw new InvalidArgumentException(
                    'کد ارز باید سه حرف انگلیسی باشد.'
                );
            }

            $this->assertOperator($operator);

            if (in_array($operator, ['above', 'below', 'equals'], true)) {
                if ($value === null || !is_numeric($value)) {
                    throw new InvalidArgumentException(
                        'این شرط ارز به مقدار عددی نیاز دارد.'
                    );
                }
            }

            if (!in_array($operator, ['above', 'below', 'equals', 'changes'], true)) {
                throw new InvalidArgumentException(
                    'برای ارز فقط above، below، equals و changes مجاز است.'
                );
            }

            return [
                'alert_type' => 'currency',
                'subject' => $base,
                'secondary_subject' => $quote,
                'operator' => $operator,
                'threshold_value' => $value !== null && is_numeric($value)
                    ? (float) $value
                    : null,
                'comparison_value' => null,
            ];
        }

        if (in_array($type, ['temperature', 'wind'], true)) {
            $operatorIndex = $this->operatorIndex($tokens);

            if ($operatorIndex === null || $operatorIndex < 1) {
                throw new InvalidArgumentException(
                    'نام شهر و عملگر شرط مشخص نیست.'
                );
            }

            $city = implode(' ', array_slice($tokens, 0, $operatorIndex));
            $operator = mb_strtolower((string) $tokens[$operatorIndex]);
            $value = $tokens[$operatorIndex + 1] ?? null;
            $this->assertOperator($operator);

            if (!in_array($operator, ['above', 'below', 'equals', 'changes'], true)) {
                throw new InvalidArgumentException(
                    'برای مقدار عددی فقط above، below، equals و changes مجاز است.'
                );
            }

            if (
                in_array($operator, ['above', 'below', 'equals'], true)
                && ($value === null || !is_numeric($value))
            ) {
                throw new InvalidArgumentException(
                    'مقدار آستانه باید عدد باشد.'
                );
            }

            return [
                'alert_type' => $type,
                'subject' => $city,
                'secondary_subject' => null,
                'operator' => $operator,
                'threshold_value' => $value !== null && is_numeric($value)
                    ? (float) $value
                    : null,
                'comparison_value' => null,
            ];
        }

        if ($type === 'weather') {
            if (count($tokens) < 2) {
                throw new InvalidArgumentException(
                    'نام شهر و وضعیت هوا لازم است.'
                );
            }

            $operatorIndex = $this->operatorIndex($tokens);

            if ($operatorIndex !== null) {
                if ($operatorIndex < 1) {
                    throw new InvalidArgumentException(
                        'نام شهر وارد نشده است.'
                    );
                }

                $city = implode(' ', array_slice($tokens, 0, $operatorIndex));
                $operator = mb_strtolower((string) $tokens[$operatorIndex]);
                $target = implode(' ', array_slice($tokens, $operatorIndex + 1));

                if ($operator !== 'changes' && $target === '') {
                    throw new InvalidArgumentException(
                        'مقدار مقایسه وارد نشده است.'
                    );
                }

                if (!in_array($operator, ['equals', 'changes', 'contains', 'starts', 'stops'], true)) {
                    throw new InvalidArgumentException(
                        'عملگر وضعیت هوا معتبر نیست.'
                    );
                }
            } else {
                $target = mb_strtolower((string) array_pop($tokens));
                $city = implode(' ', $tokens);
                $operator = 'starts';
            }

            $target = $this->normalizeWeatherTarget($target);

            return [
                'alert_type' => 'weather_condition',
                'subject' => $city,
                'secondary_subject' => null,
                'operator' => $operator,
                'threshold_value' => null,
                'comparison_value' => $operator === 'changes'
                    ? null
                    : $target,
            ];
        }

        throw new InvalidArgumentException(
            'نوع هشدار باید weather، temperature، wind یا currency باشد.'
        );
    }

    private function createSubscription(
        MessageContext $context,
        string $arguments
    ): void {
        $userId = $this->privateUser($context);

        if ($userId === null || !$this->allow($context)) {
            return;
        }

        if (
            $this->subscriptions->countActiveForUser($userId)
            >= max(1, $this->maxSubscriptionsPerUser)
        ) {
            $context->reply(
                'به سقف اشتراک‌های فعال رسیده‌ای.'
            );
            return;
        }

        try {
            $parsed = $this->parseSubscription(
                $arguments,
                $this->timezone($context)
            );
            $this->data->subscriptionMessage($parsed);
            $id = $this->subscriptions->create([
                'user_id' => $userId,
                'chat_id' => $context->chatId,
                ...$parsed,
            ]);

            $context->reply(
                "اشتراک ساخته شد. ✅\n\n"
                . "🆔 #{$id}\n"
                . "نوع: {$parsed['subscription_type']}\n"
                . "موضوع: {$parsed['subject']}\n"
                . "زمان‌بندی: {$parsed['frequency']} {$parsed['schedule_time']}\n"
                . 'اجرای بعدی: '
                . date('Y-m-d H:i', (int) $parsed['next_run_at'])
                . "\nمنطقه زمانی: {$parsed['timezone']}\n\n"
                . "لغو: /subscriptioncancel {$id}"
            );
        } catch (InvalidArgumentException $exception) {
            $context->reply(
                "اشتراک ساخته نشد. ⚠️\n\n"
                . $exception->getMessage()
                . "\n\n"
                . $this->subscriptionUsage()
            );
        } catch (Throwable) {
            $context->reply(
                'ساخت اشتراک با خطای موقت مواجه شد.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseSubscription(
        string $input,
        string $timezone
    ): array {
        $input = $this->normalizeDigits(
            preg_replace('/\s+/u', ' ', trim($input)) ?? trim($input)
        );
        $tokens = preg_split('/\s+/u', $input, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($tokens) || count($tokens) < 2) {
            throw new InvalidArgumentException(
                'نوع و موضوع اشتراک مشخص نیست.'
            );
        }

        $type = mb_strtolower((string) array_shift($tokens));

        if (!in_array($type, ['weather', 'country'], true)) {
            throw new InvalidArgumentException(
                'نوع اشتراک باید weather یا country باشد.'
            );
        }

        $frequencyIndex = null;

        foreach ($tokens as $index => $token) {
            if (in_array(mb_strtolower($token), ['daily', 'weekly', 'monthly'], true)) {
                $frequencyIndex = $index;
                break;
            }
        }

        if ($frequencyIndex === null) {
            if ($type !== 'country') {
                throw new InvalidArgumentException(
                    'زمان‌بندی اشتراک وارد نشده است.'
                );
            }

            $subject = implode(' ', $tokens);
            $frequency = 'monthly';
            $time = '09:00';
            $weekday = null;
            $monthDay = 1;
        } else {
            $subject = implode(' ', array_slice($tokens, 0, $frequencyIndex));
            $frequency = mb_strtolower((string) $tokens[$frequencyIndex]);
            $tail = array_slice($tokens, $frequencyIndex + 1);
            $weekday = null;
            $monthDay = null;

            if ($frequency === 'daily') {
                $time = (string) ($tail[0] ?? '');
            } elseif ($frequency === 'weekly') {
                $weekday = $this->schedule->weekday((string) ($tail[0] ?? ''));
                $time = (string) ($tail[1] ?? '');
            } else {
                $monthDay = isset($tail[0]) ? (int) $tail[0] : 0;
                $time = (string) ($tail[1] ?? '');
            }
        }

        if (trim($subject) === '') {
            throw new InvalidArgumentException(
                'موضوع اشتراک وارد نشده است.'
            );
        }

        $nextRunAt = $this->schedule->nextRun(
            $frequency,
            $time,
            $timezone,
            $weekday,
            $monthDay
        );

        return [
            'subscription_type' => $type,
            'subject' => trim($subject),
            'frequency' => $frequency,
            'schedule_time' => $time,
            'weekday' => $weekday,
            'month_day' => $monthDay,
            'timezone' => $timezone,
            'next_run_at' => $nextRunAt,
        ];
    }

    private function listAlerts(MessageContext $context): void
    {
        $userId = $this->privateUser($context);

        if ($userId === null) {
            return;
        }

        $rows = $this->alerts->forUser($userId);

        if ($rows === []) {
            $context->reply(
                "هشدار فعالی نداری.\n\n"
                . $this->alertUsage()
            );
            return;
        }

        $text = "🔔 هشدارهای من\n\n";

        foreach ($rows as $row) {
            $text .= '#'
                . (int) $row['id']
                . ' · '
                . (string) $row['status']
                . "\n"
                . $this->alertDescription($row)
                . "\nبررسی بعدی: "
                . date('Y-m-d H:i', (int) $row['next_check_at'])
                . "\nلغو: /alertcancel "
                . (int) $row['id']
                . "\n\n";
        }

        $context->reply(mb_substr(trim($text), 0, 3900));
    }

    private function listSubscriptions(MessageContext $context): void
    {
        $userId = $this->privateUser($context);

        if ($userId === null) {
            return;
        }

        $rows = $this->subscriptions->forUser($userId);

        if ($rows === []) {
            $context->reply(
                "اشتراک فعالی نداری.\n\n"
                . $this->subscriptionUsage()
            );
            return;
        }

        $text = "📬 اشتراک‌های من\n\n";

        foreach ($rows as $row) {
            $schedule = (string) $row['frequency'];

            if ($row['frequency'] === 'weekly') {
                $schedule .= ' ' . $this->schedule->weekdayName((int) $row['weekday']);
            } elseif ($row['frequency'] === 'monthly') {
                $schedule .= ' day ' . (int) $row['month_day'];
            }

            $text .= '#'
                . (int) $row['id']
                . ' · '
                . (string) $row['status']
                . "\n"
                . (string) $row['subscription_type']
                . ': '
                . (string) $row['subject']
                . "\n{$schedule} "
                . (string) $row['schedule_time']
                . " · "
                . (string) $row['timezone']
                . "\nلغو: /subscriptioncancel "
                . (int) $row['id']
                . "\n\n";
        }

        $context->reply(mb_substr(trim($text), 0, 3900));
    }

    private function changeAlert(
        MessageContext $context,
        string $arguments,
        string $action
    ): void {
        $userId = $this->privateUser($context);
        $id = $this->positiveId($arguments);

        if ($userId === null || $id === null) {
            if ($userId !== null) {
                $context->reply('شناسه هشدار معتبر نیست.');
            }
            return;
        }

        $changed = match ($action) {
            'pause' => $this->alerts->pauseForUser($userId, $id),
            'resume' => $this->alerts->resumeForUser($userId, $id),
            default => $this->alerts->cancelForUser($userId, $id),
        };

        $context->reply(
            $changed
                ? "عملیات روی هشدار #{$id} انجام شد. ✅"
                : 'هشدار قابل تغییر پیدا نشد.'
        );
    }

    private function changeSubscription(
        MessageContext $context,
        string $arguments,
        string $action
    ): void {
        $userId = $this->privateUser($context);
        $id = $this->positiveId($arguments);

        if ($userId === null || $id === null) {
            if ($userId !== null) {
                $context->reply('شناسه اشتراک معتبر نیست.');
            }
            return;
        }

        if ($action === 'resume') {
            $rows = $this->subscriptions->forUser($userId, 200);
            $row = null;

            foreach ($rows as $candidate) {
                if ((int) $candidate['id'] === $id) {
                    $row = $candidate;
                    break;
                }
            }

            $changed = $row !== null
                && $this->subscriptions->resumeForUser(
                    $userId,
                    $id,
                    $this->schedule->nextRun(
                        (string) $row['frequency'],
                        (string) $row['schedule_time'],
                        (string) $row['timezone'],
                        isset($row['weekday']) ? (int) $row['weekday'] : null,
                        isset($row['month_day']) ? (int) $row['month_day'] : null
                    )
                );
        } elseif ($action === 'pause') {
            $changed = $this->subscriptions->pauseForUser($userId, $id);
        } else {
            $changed = $this->subscriptions->cancelForUser($userId, $id);
        }

        $context->reply(
            $changed
                ? "عملیات روی اشتراک #{$id} انجام شد. ✅"
                : 'اشتراک قابل تغییر پیدا نشد.'
        );
    }

    /**
     * @param array<string, mixed> $alert
     */
    private function alertDescription(array $alert): string
    {
        $type = (string) ($alert['alert_type'] ?? '');
        $subject = (string) ($alert['subject'] ?? '');
        $operator = (string) ($alert['operator'] ?? '');
        $value = $alert['threshold_value']
            ?? $alert['comparison_value']
            ?? '';

        if ($type === 'currency') {
            return 'ارز '
                . $subject
                . '/'
                . (string) ($alert['secondary_subject'] ?? '')
                . " · {$operator} {$value}";
        }

        return $type
            . ' · '
            . $subject
            . " · {$operator} {$value}";
    }

    private function operatorIndex(array $tokens): ?int
    {
        foreach ($tokens as $index => $token) {
            if (in_array(mb_strtolower((string) $token), self::OPERATORS, true)) {
                return $index;
            }
        }

        return null;
    }

    private function assertOperator(string $operator): void
    {
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException(
                'عملگر باید یکی از above، below، equals، changes، contains، starts یا stops باشد.'
            );
        }
    }

    private function normalizeWeatherTarget(string $value): string
    {
        return match (mb_strtolower(trim($value))) {
            'rain', 'باران', 'بارانی' => 'rain',
            'snow', 'برف', 'برفی' => 'snow',
            'clear', 'صاف' => 'clear',
            'cloud', 'cloudy', 'ابری' => 'cloud',
            'fog', 'مه', 'مهآلود', 'مه‌آلود' => 'fog',
            'storm', 'طوفان', 'رعدوبرق' => 'storm',
            '' => '',
            default => mb_strtolower(trim($value)),
        };
    }

    private function timezone(MessageContext $context): string
    {
        $value = $this->preferences->get(
            $context->actorKey(),
            'timezone',
            $this->defaultTimezone
        );

        return $value !== null && trim($value) !== ''
            ? trim($value)
            : 'Asia/Tehran';
    }

    private function privateUser(MessageContext $context): ?int
    {
        if (!$context->isPrivate() || $context->userId === null) {
            $context->reply(
                'مدیریت هشدارها و اشتراک‌ها فقط در چت خصوصی ربات انجام می‌شود.'
            );
            return null;
        }

        return $context->userId;
    }

    private function allow(MessageContext $context): bool
    {
        $result = $this->rateLimiter->attempt(
            'alerts:' . $context->actorKey(),
            max(1, $this->maxAttempts),
            max(1, $this->windowSeconds)
        );

        if ($result->allowed) {
            return true;
        }

        $context->reply(
            "درخواست‌ها زیاد است؛ {$result->retryAfter} ثانیه دیگر تلاش کن."
        );
        return false;
    }

    private function positiveId(string $value): ?int
    {
        $value = $this->normalizeDigits(trim($value));

        if (preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function alertUsage(): string
    {
        return "/alert weather Tehran rain\n"
            . "/alert weather Tehran stops rain\n"
            . "/alert temperature Tehran below 0\n"
            . "/alert wind Tehran above 60\n"
            . "/alert currency USD EUR above 0.90";
    }

    private function subscriptionUsage(): string
    {
        return "/subscribe weather Tehran daily 08:00\n"
            . "/subscribe weather Tehran weekly saturday 08:00\n"
            . "/subscribe country Iran monthly 1 09:00\n"
            . "/subscribe country Iran";
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
