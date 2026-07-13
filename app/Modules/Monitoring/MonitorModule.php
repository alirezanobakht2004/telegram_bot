<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\RateLimiter;
use SmartToolbox\Core\UserPreferenceStore;
use SmartToolbox\Modules\Alerts\ScheduleCalculator;
use Throwable;

final class MonitorModule implements ModuleInterface
{
    public function __construct(
        private readonly MonitorRepository $repository,
        private readonly MonitorProbe $probe,
        private readonly SslInspector $ssl,
        private readonly DnsInspector $dns,
        private readonly ScheduleCalculator $schedule,
        private readonly UserPreferenceStore $preferences,
        private readonly RateLimiter $rateLimiter,
        private readonly string $defaultTimezone = 'Asia/Tehran',
        private readonly int $maxMonitorsPerUser = 20,
        private readonly int $minimumIntervalSeconds = 300,
        private readonly int $maximumIntervalSeconds = 86400,
        private readonly int $maxAttempts = 30,
        private readonly int $windowSeconds = 60
    ) {
    }

    public function register(CommandRouter $router): void
    {
        $router->command(
            'status',
            fn (MessageContext $context, string $arguments): mixed =>
                $this->status($context, $arguments),
            'monitoring'
        );
        $router->command(
            'monitor',
            fn (MessageContext $context, string $arguments): mixed =>
                $this->create($context, $arguments),
            'monitoring'
        );
        $router->command(
            'monitors',
            fn (MessageContext $context): mixed =>
                $this->list($context),
            'monitoring'
        );
        $router->command(
            'monitorpause',
            fn (MessageContext $context, string $arguments): mixed =>
                $this->change($context, $arguments, 'pause'),
            'monitoring'
        );
        $router->command(
            'monitorresume',
            fn (MessageContext $context, string $arguments): mixed =>
                $this->change($context, $arguments, 'resume'),
            'monitoring'
        );
        $router->command(
            'monitorcancel',
            fn (MessageContext $context, string $arguments): mixed =>
                $this->change($context, $arguments, 'cancel'),
            'monitoring'
        );
        $router->command(
            'monitorreport',
            fn (MessageContext $context, string $arguments): mixed =>
                $this->configureReport($context, $arguments),
            'monitoring'
        );
        $router->command(
            'ssl',
            fn (MessageContext $context, string $arguments): mixed =>
                $this->ssl($context, $arguments),
            'monitoring'
        );
        $router->command(
            'dns',
            fn (MessageContext $context, string $arguments): mixed =>
                $this->dns($context, $arguments),
            'monitoring'
        );
        $router->command(
            'headers',
            fn (MessageContext $context, string $arguments): mixed =>
                $this->headers($context, $arguments),
            'monitoring'
        );
        $router->command(
            'uptime',
            fn (MessageContext $context, string $arguments): mixed =>
                $this->uptime($context, $arguments),
            'monitoring'
        );
        $router->text(
            '📡 مانیتورینگ',
            fn (MessageContext $context): mixed =>
                $this->menu($context),
            'monitoring'
        );
    }

    private function menu(MessageContext $context): void
    {
        $context->reply(
            "📡 مانیتورینگ سایت و سرور\n\n"
            . "/status https://example.com\n"
            . "/monitor https://example.com 5m\n"
            . "/monitors\n"
            . "/ssl example.com\n"
            . "/dns example.com\n"
            . "/headers https://example.com\n"
            . "/uptime 12\n"
            . "/monitorreport 12 on 09:00"
        );
    }

    private function status(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        $url = trim($arguments);

        if ($url === '') {
            $context->reply(
                'نمونه: /status https://example.com'
            );
            return;
        }

        try {
            $result = $this->probe->probe($url);
            $up = $result['status_code'] >= 200
                && $result['status_code'] < 400;
            $redirects = count($result['redirects']);

            $context->reply(
                ($up ? '✅ سایت در دسترس است' : '⚠️ پاسخ سایت ناموفق است')
                . "\n\nURL: {$result['final_url']}\n"
                . "HTTP: {$result['status_code']}\n"
                . "زمان پاسخ: {$result['response_ms']} ms\n"
                . "TTFB: {$result['ttfb_ms']} ms\n"
                . "IP: {$result['primary_ip']}\n"
                . "Redirect: {$redirects}\n"
                . 'Content-Type: '
                . ($result['content_type'] ?? '—')
            );
        } catch (Throwable $exception) {
            $context->reply(
                "❌ بررسی سایت ناموفق بود\n\n"
                . $this->safeError($exception)
            );
        }
    }

    private function create(
        MessageContext $context,
        string $arguments
    ): void {
        $userId = $this->privateUser($context);

        if ($userId === null || !$this->allow($context)) {
            return;
        }

        if (
            $this->repository->countActiveForUser($userId)
            >= max(1, $this->maxMonitorsPerUser)
        ) {
            $context->reply(
                'به سقف مانیتورهای فعال رسیده‌ای.'
            );
            return;
        }

        $parts = preg_split(
            '/\s+/u',
            trim($arguments),
            2,
            PREG_SPLIT_NO_EMPTY
        );

        if (!is_array($parts) || count($parts) !== 2) {
            $context->reply(
                'نمونه: /monitor https://example.com 5m'
            );
            return;
        }

        try {
            $url = $this->probe->normalizeUrl($parts[0]);
            $interval = $this->interval($parts[1]);
            $id = $this->repository->create(
                userId: $userId,
                chatId: $context->chatId,
                url: $url,
                normalizedUrl: $this->canonicalUrl($url),
                intervalSeconds: $interval,
                timezone: $this->timezone($context)
            );

            $context->reply(
                "مانیتور ساخته شد. ✅\n\n"
                . "🆔 #{$id}\n"
                . "URL: {$url}\n"
                . "فاصله بررسی: {$interval} ثانیه\n\n"
                . "توقف: /monitorpause {$id}\n"
                . "لغو: /monitorcancel {$id}\n"
                . "Uptime: /uptime {$id}"
            );
        } catch (Throwable $exception) {
            $context->reply(
                "مانیتور ساخته نشد. ⚠️\n\n"
                . $this->safeError($exception)
            );
        }
    }

    private function list(MessageContext $context): void
    {
        $userId = $this->privateUser($context);

        if ($userId === null) {
            return;
        }

        $rows = $this->repository->forUser($userId);

        if ($rows === []) {
            $context->reply(
                "مانیتوری نداری.\n\n"
                . '/monitor https://example.com 5m'
            );
            return;
        }

        $text = "📡 مانیتورهای من\n\n";

        foreach ($rows as $row) {
            $state = match ((string) $row['last_state']) {
                'up' => '✅ UP',
                'down' => '❌ DOWN',
                default => '⏳ UNKNOWN',
            };
            $text .= '#'
                . (int) $row['id']
                . ' · '
                . (string) $row['status']
                . " · {$state}\n"
                . (string) $row['url']
                . "\nHTTP: "
                . ($row['last_status_code'] ?? '—')
                . ' · '
                . ($row['last_response_ms'] ?? '—')
                . " ms\n"
                . "Uptime: /uptime " . (int) $row['id']
                . "\n\n";
        }

        $context->reply(mb_substr(trim($text), 0, 3900));
    }

    private function change(
        MessageContext $context,
        string $arguments,
        string $action
    ): void {
        $userId = $this->privateUser($context);
        $id = $this->positiveId($arguments);

        if ($userId === null || $id === null) {
            if ($userId !== null) {
                $context->reply('شناسه مانیتور معتبر نیست.');
            }
            return;
        }

        $changed = match ($action) {
            'pause' => $this->repository->pauseForUser($userId, $id),
            'resume' => $this->repository->resumeForUser($userId, $id),
            default => $this->repository->cancelForUser($userId, $id),
        };

        $context->reply(
            $changed
                ? "عملیات روی مانیتور #{$id} انجام شد. ✅"
                : 'مانیتور قابل تغییر پیدا نشد.'
        );
    }

    private function configureReport(
        MessageContext $context,
        string $arguments
    ): void {
        $userId = $this->privateUser($context);

        if ($userId === null) {
            return;
        }

        $parts = preg_split('/\s+/u', trim($arguments), -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($parts) || count($parts) < 2) {
            $context->reply(
                "نمونه:\n/monitorreport 12 on 09:00\n/monitorreport 12 off"
            );
            return;
        }

        $id = $this->positiveId((string) $parts[0]);
        $mode = mb_strtolower((string) $parts[1]);

        if ($id === null || !in_array($mode, ['on', 'off'], true)) {
            $context->reply('پارامترهای گزارش روزانه معتبر نیستند.');
            return;
        }

        try {
            $enabled = $mode === 'on';
            $time = $enabled ? (string) ($parts[2] ?? '09:00') : '09:00';
            $timezone = $this->timezone($context);
            $next = $enabled
                ? $this->schedule->nextRun('daily', $time, $timezone)
                : null;
            $changed = $this->repository->configureDailyReport(
                $userId,
                $id,
                $enabled,
                $time,
                $timezone,
                $next
            );

            $context->reply(
                $changed
                    ? ($enabled
                        ? "گزارش روزانه مانیتور #{$id} برای {$time} فعال شد. ✅"
                        : "گزارش روزانه مانیتور #{$id} غیرفعال شد.")
                    : 'مانیتور پیدا نشد.'
            );
        } catch (Throwable $exception) {
            $context->reply($this->safeError($exception));
        }
    }

    private function ssl(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        try {
            $result = $this->ssl->inspect($arguments);
            $context->reply(
                "🔐 اطلاعات SSL\n\n"
                . "دامنه: {$result['host']}\n"
                . "Subject: {$result['subject']}\n"
                . "Issuer: {$result['issuer']}\n"
                . 'شروع: ' . date('Y-m-d H:i', $result['valid_from']) . "\n"
                . 'انقضا: ' . date('Y-m-d H:i', $result['valid_to']) . "\n"
                . "روز باقی‌مانده: {$result['days_remaining']}\n"
                . "Serial: {$result['serial']}\n"
                . 'SAN: ' . implode('، ', array_slice($result['san'], 0, 10))
            );
        } catch (Throwable $exception) {
            $context->reply(
                "بررسی SSL ناموفق بود. ⚠️\n\n"
                . $this->safeError($exception)
            );
        }
    }

    private function dns(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        try {
            $result = $this->dns->inspect($arguments);
            $text = "🌐 DNS: {$result['domain']}\n\n";

            foreach ($result['records'] as $record) {
                $text .= $record['type']
                    . ' · '
                    . $record['value']
                    . ' · TTL '
                    . $record['ttl']
                    . "\n";
            }

            $context->reply(mb_substr(trim($text), 0, 3900));
        } catch (Throwable $exception) {
            $context->reply(
                "بررسی DNS ناموفق بود. ⚠️\n\n"
                . $this->safeError($exception)
            );
        }
    }

    private function headers(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        try {
            $result = $this->probe->probe(trim($arguments));
            $interesting = [
                'content-type',
                'content-length',
                'server',
                'location',
                'strict-transport-security',
                'content-security-policy',
                'x-frame-options',
                'x-content-type-options',
                'referrer-policy',
                'permissions-policy',
                'cache-control',
            ];
            $text = "📨 Headerهای HTTP\n\n"
                . "URL: {$result['final_url']}\n"
                . "Status: {$result['status_code']}\n\n";

            foreach ($interesting as $name) {
                $values = $result['headers'][$name] ?? [];

                if ($values !== []) {
                    $text .= $name . ': '
                        . implode(' | ', $values)
                        . "\n";
                }
            }

            $missing = array_values(array_filter(
                [
                    'strict-transport-security',
                    'content-security-policy',
                    'x-frame-options',
                    'x-content-type-options',
                    'referrer-policy',
                ],
                fn (string $name): bool => !isset($result['headers'][$name])
            ));

            if ($missing !== []) {
                $text .= "\n⚠️ Headerهای امنیتی غایب:\n"
                    . implode('، ', $missing);
            }

            $context->reply(mb_substr(trim($text), 0, 3900));
        } catch (Throwable $exception) {
            $context->reply(
                "بررسی Headerها ناموفق بود. ⚠️\n\n"
                . $this->safeError($exception)
            );
        }
    }

    private function uptime(
        MessageContext $context,
        string $arguments
    ): void {
        $userId = $this->privateUser($context);
        $id = $this->positiveId($arguments);

        if ($userId === null || $id === null) {
            if ($userId !== null) {
                $context->reply('نمونه: /uptime 12');
            }
            return;
        }

        $monitor = $this->repository->findForUser($userId, $id);

        if ($monitor === null) {
            $context->reply('مانیتور پیدا نشد.');
            return;
        }

        $day = $this->repository->uptime($id, 1);
        $week = $this->repository->uptime($id, 7);
        $month = $this->repository->uptime($id, 30);

        $context->reply(
            "📈 Uptime مانیتور #{$id}\n\n"
            . (string) $monitor['url'] . "\n"
            . 'وضعیت فعلی: ' . (string) $monitor['last_state'] . "\n\n"
            . '۲۴ ساعت: ' . $day['uptime'] . '% · '
            . $day['checks'] . " بررسی\n"
            . '۷ روز: ' . $week['uptime'] . '% · '
            . $week['incidents'] . " رخداد\n"
            . '۳۰ روز: ' . $month['uptime'] . '% · میانگین '
            . $month['average_response_ms'] . ' ms'
        );
    }

    private function interval(string $value): int
    {
        $value = $this->normalizeDigits(mb_strtolower(trim($value)));

        if (preg_match('/^(\d+)\s*(s|m|h|d)$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException(
                'فاصله باید مانند 5m، 1h یا 1d باشد.'
            );
        }

        $amount = (int) $matches[1];
        $seconds = match ($matches[2]) {
            's' => $amount,
            'm' => $amount * 60,
            'h' => $amount * 3600,
            'd' => $amount * 86400,
        };

        if (
            $seconds < max(60, $this->minimumIntervalSeconds)
            || $seconds > max($this->minimumIntervalSeconds, $this->maximumIntervalSeconds)
        ) {
            throw new InvalidArgumentException(
                'فاصله مانیتور خارج از محدوده مجاز است.'
            );
        }

        return $seconds;
    }

    private function canonicalUrl(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            throw new RuntimeException('URL معتبر نیست.');
        }

        $scheme = mb_strtolower((string) $parts['scheme']);
        $host = mb_strtolower(rtrim((string) $parts['host'], '.'));
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . ($path !== '' ? $path : '/') . $query;
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
                'مدیریت مانیتورها فقط در چت خصوصی ربات انجام می‌شود.'
            );
            return null;
        }

        return $context->userId;
    }

    private function allow(MessageContext $context): bool
    {
        $result = $this->rateLimiter->attempt(
            'monitoring:' . $context->actorKey(),
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

    private function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function safeError(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        return $message !== ''
            ? mb_substr($message, 0, 700)
            : 'خطای موقت.';
    }
}
