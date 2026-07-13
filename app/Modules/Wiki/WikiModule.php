<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Wiki;

use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\RateLimiter;
use Throwable;

final class WikiModule implements ModuleInterface
{
    public function __construct(
        private readonly WikiClient $client,
        private readonly RateLimiter $rateLimiter,
        private readonly int $maxAttempts = 30,
        private readonly int $windowSeconds = 60,
        private readonly int $maxQueryLength = 150
    ) {
    }

    public function register(CommandRouter $router): void
    {
        $router->text(
            '📚 ویکی‌پدیا',
            function (
                MessageContext $context
            ): void {
                $context->reply(
                    "📚 ویکی‌پدیا\n\n"
                    . "/wiki آلبرت اینشتین\n"
                    . "/wiki PHP\n"
                    . "/randomwiki\n"
                    . "/today\n"
                    . "/onthisday 7/20"
                );
            },
            'wiki'
        );

        $router->command('wiki', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->wiki($context, $arguments);
        });

        $router->command('randomwiki', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->random($context, $arguments);
        });

        $router->command('today', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->today($context, $arguments);
        });

        $router->command('onthisday', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->onThisDay($context, $arguments);
        });
    }

    private function wiki(
        MessageContext $context,
        string $query
    ): void {
        $query = trim($query);

        if ($query === '') {
            $context->reply(
                "عبارت جست‌وجو را وارد کن.\n\n"
                . "/wiki آلبرت اینشتین\n"
                . '/wiki PHP'
            );
            return;
        }

        if (mb_strlen($query) > $this->safeMaxQueryLength()) {
            $context->reply('عبارت جست‌وجو بیش از حد طولانی است.');
            return;
        }

        if (!$this->allow($context)) {
            return;
        }

        try {
            $article = $this->client->first(
                $query,
                $this->client->detectLanguage($query)
            );

            if ($article === null) {
                $context->reply('مقاله‌ای پیدا نشد.');
                return;
            }

            $context->reply(
                $this->articleText($article),
                [
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => 'بازکردن در ویکی‌پدیا',
                                    'url' => $article['url'],
                                ],
                            ],
                        ],
                    ],
                ]
            );
        } catch (Throwable $exception) {
            $context->reply(
                'دریافت اطلاعات ویکی‌پدیا ممکن نشد: '
                . $exception->getMessage()
            );
        }
    }

    private function random(
        MessageContext $context,
        string $arguments
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        $language = mb_strtolower(trim($arguments));
        $language = in_array($language, ['fa', 'en'], true)
            ? $language
            : 'fa';

        try {
            $article = $this->client->random($language);
            $context->reply(
                "🎲 مقاله تصادفی\n\n"
                . $this->articleText($article),
                [
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => 'بازکردن مقاله',
                                    'url' => $article['url'],
                                ],
                            ],
                        ],
                    ],
                ]
            );
        } catch (Throwable $exception) {
            $context->reply(
                'دریافت مقاله تصادفی ممکن نشد: '
                . $exception->getMessage()
            );
        }
    }

    private function today(
        MessageContext $context,
        string $arguments
    ): void {
        $language = mb_strtolower(trim($arguments));
        $language = in_array($language, ['fa', 'en'], true)
            ? $language
            : 'fa';

        $now = new \DateTimeImmutable('now');
        $this->sendOnThisDay(
            $context,
            $language,
            (int) $now->format('n'),
            (int) $now->format('j')
        );
    }

    private function onThisDay(
        MessageContext $context,
        string $arguments
    ): void {
        $value = trim($arguments);
        $language = 'fa';

        if ($value === '') {
            $now = new \DateTimeImmutable('now');
            $this->sendOnThisDay(
                $context,
                $language,
                (int) $now->format('n'),
                (int) $now->format('j')
            );
            return;
        }

        $parts = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if (is_array($parts) && in_array(mb_strtolower(end($parts)), ['fa', 'en'], true)) {
            $language = mb_strtolower((string) array_pop($parts));
            $value = implode(' ', $parts);
        }

        $value = str_replace(['-', '.'], '/', $value);

        if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $value, $matches) !== 1) {
            $context->reply(
                "فرمت تاریخ: ماه/روز\n\n"
                . '/onthisday 7/20\n'
                . '/onthisday 7/20 en'
            );
            return;
        }

        $this->sendOnThisDay(
            $context,
            $language,
            (int) $matches[1],
            (int) $matches[2]
        );
    }

    private function sendOnThisDay(
        MessageContext $context,
        string $language,
        int $month,
        int $day
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        try {
            $data = $this->client->onThisDay(
                $language,
                $month,
                $day
            );

            $text = sprintf(
                "📅 در چنین روزی — %02d/%02d\n🌐 %s\n\n",
                $month,
                $day,
                $data['language']
            );

            $sections = [
                'selected' => '📌 رویدادها',
                'births' => '🎂 تولدها',
                'deaths' => '🕯 درگذشت‌ها',
                'holidays' => '🎉 مناسبت‌ها',
            ];

            foreach ($sections as $key => $label) {
                $items = $data[$key] ?? [];

                if (!is_array($items) || $items === []) {
                    continue;
                }

                $text .= $label . "\n";

                foreach (array_slice($items, 0, 4) as $item) {
                    $year = trim((string) ($item['year'] ?? ''));
                    $eventText = trim((string) ($item['text'] ?? ''));

                    if ($eventText === '') {
                        continue;
                    }

                    $prefix = $year !== '' ? $year . ': ' : '';
                    $text .= '• ' . $prefix . $eventText . "\n";
                }

                $text .= "\n";
            }

            if (mb_strlen($text) > 3900) {
                $text = mb_substr($text, 0, 3890) . '…';
            }

            $context->reply(trim($text));
        } catch (Throwable $exception) {
            $context->reply(
                'دریافت رویدادهای تاریخی ممکن نشد: '
                . $exception->getMessage()
            );
        }
    }

    /**
     * @param array<string, mixed> $article
     */
    private function articleText(array $article): string
    {
        $extract = trim((string) ($article['extract'] ?? ''));

        if ($extract === '') {
            $extract = 'خلاصه‌ای برای این مقاله در دسترس نیست.';
        }

        if (mb_strlen($extract) > 3000) {
            $extract = mb_substr($extract, 0, 2990) . '…';
        }

        return "📚 "
            . (string) $article['title']
            . "\n\n"
            . $extract
            . "\n\n🌐 "
            . (string) $article['language']
            . "\n🔗 "
            . (string) $article['url'];
    }

    private function allow(MessageContext $context): bool
    {
        $result = $this->rateLimiter->attempt(
            'wiki:' . $context->actorKey(),
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

    private function safeMaxQueryLength(): int
    {
        return max(20, min(500, $this->maxQueryLength));
    }
}
