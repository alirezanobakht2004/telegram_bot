<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\GitHub;

use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\RateLimiter;
use Throwable;

final class GitHubModule implements ModuleInterface
{
    public function __construct(
        private readonly GitHubClient $client,
        private readonly GitHubWatchRepository $watches,
        private readonly RateLimiter $rateLimiter,
        private readonly int $maxWatchesPerUser = 20,
        private readonly int $maxAttempts = 30,
        private readonly int $windowSeconds = 60
    ) {
    }

    public function register(CommandRouter $router): void
    {
        $router->text(
            '🐙 GitHub',
            function (
                MessageContext $context
            ): void {
                $context->reply(
                    "🐙 ابزارهای GitHub\n\n"
                    . "/github owner/repository\n"
                    . "/release owner/repository\n"
                    . "/issues owner/repository\n"
                    . "/watchrelease owner/repository\n"
                    . "/releasewatches"
                );
            },
            'github'
        );

        $router->command('github', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->repository($context, $arguments);
        });

        $router->command('release', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->release($context, $arguments);
        });

        $router->command('issues', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->issues($context, $arguments);
        });

        $router->command('watchrelease', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->watch($context, $arguments);
        });

        $router->command('unwatchrelease', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->unwatch($context, $arguments);
        });

        $router->command('releasewatches', function (
            MessageContext $context
        ): void {
            $this->listWatches($context);
        });
    }

    private function repository(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->validateInput($context, $arguments) || !$this->allow($context)) {
            return;
        }

        try {
            $repo = $this->client->repository($arguments);
            $commit = is_array($repo['commit'] ?? null)
                ? $repo['commit']
                : null;
            $languages = is_array($repo['languages'] ?? null)
                ? implode('، ', $repo['languages'])
                : '';
            $topics = is_array($repo['topics'] ?? null)
                ? implode('، ', array_slice($repo['topics'], 0, 8))
                : '';

            $text = "🐙 GitHub — {$repo['full_name']}\n\n"
                . (($repo['description'] ?? '') !== ''
                    ? $repo['description'] . "\n\n"
                    : '')
                . "⭐ Stars: " . number_format((int) $repo['stars']) . "\n"
                . "🍴 Forks: " . number_format((int) $repo['forks']) . "\n"
                . "🧩 Open issues: " . number_format((int) $repo['open_issues']) . "\n"
                . "🌿 Branch: {$repo['default_branch']}\n"
                . "💻 Language: " . (($repo['language'] ?? '') ?: '—') . "\n"
                . "📄 License: " . (($repo['license'] ?? '') ?: '—') . "\n"
                . "📦 Archived: " . ((bool) $repo['archived'] ? 'yes' : 'no') . "\n";

            if ($languages !== '') {
                $text .= "🧬 Languages: {$languages}\n";
            }

            if ($topics !== '') {
                $text .= "🏷 Topics: {$topics}\n";
            }

            if ($commit !== null) {
                $message = trim((string) ($commit['message'] ?? ''));
                $message = strtok($message, "\r\n") ?: $message;
                $text .= "\nآخرین Commit:\n"
                    . mb_substr((string) ($commit['sha'] ?? ''), 0, 8)
                    . ' — '
                    . mb_substr($message, 0, 220)
                    . "\n"
                    . (string) ($commit['date'] ?? '');
            }

            $text .= "\n\n🔗 {$repo['url']}";

            $context->reply(trim($text), [
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => 'بازکردن مخزن',
                                'url' => $repo['url'],
                            ],
                        ],
                    ],
                ],
            ]);
        } catch (Throwable $exception) {
            $this->error($context, $exception);
        }
    }

    private function release(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->validateInput($context, $arguments) || !$this->allow($context)) {
            return;
        }

        try {
            $release = $this->client->latestRelease($arguments);

            if ($release === null) {
                $context->reply('این مخزن Release عمومی ندارد.');
                return;
            }

            $body = trim((string) ($release['body'] ?? ''));
            if (mb_strlen($body) > 2500) {
                $body = mb_substr($body, 0, 2490) . '…';
            }

            $text = "🚀 آخرین Release\n\n"
                . "مخزن: {$release['repository']}\n"
                . "نام: " . (($release['name'] ?? '') ?: '—') . "\n"
                . "Tag: {$release['tag_name']}\n"
                . "نوع: "
                . ((bool) $release['prerelease'] ? 'Pre-release' : 'Stable')
                . "\nزمان: {$release['published_at']}\n";

            if ($body !== '') {
                $text .= "\n{$body}\n";
            }

            $text .= "\n🔗 {$release['url']}";
            $context->reply(trim($text));
        } catch (Throwable $exception) {
            $this->error($context, $exception);
        }
    }

    private function issues(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->validateInput($context, $arguments) || !$this->allow($context)) {
            return;
        }

        try {
            $repo = $this->client->parseRepository($arguments);
            $issues = $this->client->issues($arguments, 8);

            if ($issues === []) {
                $context->reply(
                    "Issue بازی برای {$repo['full_name']} پیدا نشد."
                );
                return;
            }

            $text = "🧩 Issueهای باز — {$repo['full_name']}\n\n";

            foreach ($issues as $issue) {
                $labels = is_array($issue['labels'] ?? null)
                    && $issue['labels'] !== []
                    ? ' [' . implode(', ', array_slice($issue['labels'], 0, 4)) . ']'
                    : '';

                $text .= '#'
                    . $issue['number']
                    . ' '
                    . mb_substr((string) $issue['title'], 0, 180)
                    . $labels
                    . "\n💬 "
                    . (int) $issue['comments']
                    . ' · '
                    . (string) $issue['updated_at']
                    . "\n"
                    . (string) $issue['url']
                    . "\n\n";
            }

            if (mb_strlen($text) > 3900) {
                $text = mb_substr($text, 0, 3890) . '…';
            }

            $context->reply(trim($text));
        } catch (Throwable $exception) {
            $this->error($context, $exception);
        }
    }

    private function watch(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$context->isPrivate()) {
            $context->reply('Release watch فقط در چت خصوصی ساخته می‌شود.');
            return;
        }

        if ($context->userId === null || !$this->validateInput($context, $arguments) || !$this->allow($context)) {
            return;
        }

        try {
            $existing = $this->watches->forUser($context->userId, 100);
            if (count($existing) >= $this->safeMaxWatches()) {
                $context->reply('به سقف Release watchها رسیده‌ای.');
                return;
            }

            $repo = $this->client->parseRepository($arguments);
            $this->client->repository($repo['full_name']);
            $release = $this->client->latestRelease($repo['full_name']);
            $id = $this->watches->watch(
                $context->userId,
                $context->chatId,
                $repo['owner'],
                $repo['repository'],
                $release !== null && is_int($release['id'] ?? null)
                    ? $release['id']
                    : null,
                $release !== null
                    ? (string) ($release['tag_name'] ?? '')
                    : null
            );

            $baseline = $release !== null
                ? (string) ($release['tag_name'] ?? 'بدون Tag')
                : 'این مخزن هنوز Release ندارد';

            $context->reply(
                "Release watch فعال شد. 🔔\n\n"
                . "🆔 #{$id}\n"
                . "مخزن: {$repo['full_name']}\n"
                . "Baseline: {$baseline}\n\n"
                . 'فهرست: /releasewatches'
            );
        } catch (Throwable $exception) {
            $this->error($context, $exception);
        }
    }

    private function unwatch(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$context->isPrivate() || $context->userId === null) {
            $context->reply('این دستور فقط در چت خصوصی اجرا می‌شود.');
            return;
        }

        try {
            $repo = $this->client->parseRepository($arguments);
            $deleted = $this->watches->unwatch(
                $context->userId,
                $repo['owner'],
                $repo['repository']
            );

            $context->reply(
                $deleted
                    ? "Release watch مخزن {$repo['full_name']} حذف شد. ✅"
                    : 'Watch پیدا نشد.'
            );
        } catch (Throwable $exception) {
            $this->error($context, $exception);
        }
    }

    private function listWatches(
        MessageContext $context
    ): void {
        if (!$context->isPrivate() || $context->userId === null) {
            $context->reply('فهرست Watchها فقط در چت خصوصی نمایش داده می‌شود.');
            return;
        }

        $rows = $this->watches->forUser(
            $context->userId,
            $this->safeMaxWatches()
        );

        if ($rows === []) {
            $context->reply(
                "Release watch فعالی نداری.\n\n"
                . '/watchrelease owner/repository'
            );
            return;
        }

        $text = "🔔 Release watchهای من\n\n";
        foreach ($rows as $row) {
            $fullName = $row['owner'] . '/' . $row['repository'];
            $text .= '#'
                . $row['id']
                . ' — '
                . $fullName
                . "\nآخرین Tag: "
                . (($row['last_tag_name'] ?? '') ?: '—')
                . "\nآخرین بررسی: "
                . (($row['last_checked_at'] ?? '') ?: '—')
                . "\nحذف: /unwatchrelease {$fullName}\n\n";
        }

        $context->reply(trim($text));
    }

    private function validateInput(
        MessageContext $context,
        string $arguments
    ): bool {
        if (trim($arguments) === '') {
            $context->reply(
                "فرمت مخزن: owner/repository\n\n"
                . '/github php/php-src'
            );
            return false;
        }

        return true;
    }

    private function allow(MessageContext $context): bool
    {
        $result = $this->rateLimiter->attempt(
            'github:' . $context->actorKey(),
            max(1, $this->maxAttempts),
            max(1, $this->windowSeconds)
        );

        if ($result->allowed) {
            return true;
        }

        $context->reply(
            "درخواست‌های زیادی فرستادی. {$result->retryAfter} ثانیه دیگر تلاش کن."
        );
        return false;
    }

    private function safeMaxWatches(): int
    {
        return max(1, min(100, $this->maxWatchesPerUser));
    }

    private function error(
        MessageContext $context,
        Throwable $exception
    ): void {
        $context->reply(
            'دریافت اطلاعات GitHub ممکن نشد: '
            . $exception->getMessage()
        );
    }
}
