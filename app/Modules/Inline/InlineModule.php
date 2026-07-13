<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Inline;

use SmartToolbox\Core\InlineQueryContext;
use SmartToolbox\Core\InlineQueryRouter;
use SmartToolbox\Core\RateLimiter;
use Throwable;

final class InlineModule
{
    public function __construct(
        private readonly InlineDataService $data,
        private readonly InlineResultFactory $factory,
        private readonly RateLimiter $rateLimiter,
        private readonly int $cacheTime = 60,
        private readonly int $maxResults = 5,
        private readonly int $maxAttempts = 40,
        private readonly int $windowSeconds = 60
    ) {
    }

    public function register(
        InlineQueryRouter $router
    ): void {
        $router->route(
            'weather',
            function (
                InlineQueryContext $context,
                string $arguments
            ): void {
                $this->single(
                    $context,
                    'weather',
                    $arguments,
                    fn (): array =>
                        $this->data->weather(
                            $arguments
                        )
                );
            },
            'weather'
        );

        $router->route(
            'calc',
            function (
                InlineQueryContext $context,
                string $arguments
            ): void {
                $this->single(
                    $context,
                    'calc',
                    $arguments,
                    fn (): array =>
                        $this->data->calculation(
                            $arguments
                        )
                );
            },
            'calculator'
        );

        $router->route(
            'country',
            function (
                InlineQueryContext $context,
                string $arguments
            ): void {
                $this->single(
                    $context,
                    'country',
                    $arguments,
                    fn (): array =>
                        $this->data->country(
                            $arguments
                        )
                );
            },
            'countries'
        );

        $router->route(
            'currency',
            function (
                InlineQueryContext $context,
                string $arguments
            ): void {
                $this->single(
                    $context,
                    'currency',
                    $arguments,
                    fn (): array =>
                        $this->data->currency(
                            $arguments
                        )
                );
            },
            'currency'
        );

        $router->route(
            'wiki',
            function (
                InlineQueryContext $context,
                string $arguments
            ): void {
                $this->wiki(
                    $context,
                    $arguments
                );
            },
            'wiki'
        );

        $router->route(
            'github',
            function (
                InlineQueryContext $context,
                string $arguments
            ): void {
                $this->github(
                    $context,
                    $arguments
                );
            },
            'github'
        );

        $router->fallback(
            function (
                InlineQueryContext $context,
                string $query
            ): void {
                $this->help(
                    $context,
                    $query
                );
            },
            'inline'
        );
    }

    /**
     * @param callable(): array{
     *     title: string,
     *     description: string,
     *     message: string,
     *     thumbnail?: ?string
     * } $resolver
     */
    private function single(
        InlineQueryContext $context,
        string $namespace,
        string $arguments,
        callable $resolver
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        try {
            $data = $resolver();

            $context->answer(
                [
                    $this->factory->article(
                        $namespace,
                        $arguments,
                        $data['title'],
                        $data['description'],
                        $data['message'],
                        [
                            'thumbnail_url' =>
                                $data['thumbnail']
                                ?? null,
                        ]
                    ),
                ],
                $this->answerOptions()
            );
        } catch (Throwable $exception) {
            $context->answer(
                [
                    $this->factory->error(
                        $namespace,
                        $this->safeError(
                            $exception
                        )
                    ),
                ],
                [
                    'cache_time' => 1,
                    'is_personal' => true,
                ]
            );
        }
    }

    private function wiki(
        InlineQueryContext $context,
        string $arguments
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        try {
            $rows = $this->data->wiki(
                $arguments,
                $this->safeMaxResults()
            );

            $results = [];

            foreach ($rows as $row) {
                $title = (string) (
                    $row['title'] ?? ''
                );

                if ($title === '') {
                    continue;
                }

                $extract = trim(
                    (string) (
                        $row['extract']
                        ?? ''
                    )
                );

                $url = trim(
                    (string) (
                        $row['url'] ?? ''
                    )
                );

                $message = "📚 {$title}\n\n"
                    . (
                        $extract !== ''
                            ? $extract
                            : 'خلاصه‌ای در دسترس نیست.'
                    );

                if ($url !== '') {
                    $message .=
                        "\n\nمنبع: {$url}";
                }

                $results[] =
                    $this->factory->article(
                        'wiki',
                        $title . ':' . $url,
                        "📚 {$title}",
                        mb_substr(
                            $extract,
                            0,
                            220
                        ),
                        $message,
                        [
                            'thumbnail_url' =>
                                $row[
                                    'thumbnail'
                                ] ?? null,
                            'url' => $url,
                        ]
                    );
            }

            if ($results === []) {
                $results[] =
                    $this->factory->error(
                        'wiki',
                        'مقاله‌ای پیدا نشد.'
                    );
            }

            $context->answer(
                $results,
                $this->answerOptions()
            );
        } catch (Throwable $exception) {
            $context->answer(
                [
                    $this->factory->error(
                        'wiki',
                        $this->safeError(
                            $exception
                        )
                    ),
                ],
                [
                    'cache_time' => 1,
                    'is_personal' => true,
                ]
            );
        }
    }

    private function github(
        InlineQueryContext $context,
        string $arguments
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        try {
            $row = $this->data->github(
                $arguments
            );

            $fullName = (string) (
                $row['full_name']
                ?? $arguments
            );

            $description = trim(
                (string) (
                    $row['description']
                    ?? ''
                )
            );

            $languages = is_array(
                $row['languages'] ?? null
            )
                ? implode(
                    '، ',
                    array_slice(
                        $row['languages'],
                        0,
                        5
                    )
                )
                : '';

            $message = "🐙 GitHub: {$fullName}\n\n"
                . (
                    $description !== ''
                        ? $description . "\n\n"
                        : ''
                )
                . "⭐ "
                . number_format(
                    (int) (
                        $row['stars'] ?? 0
                    )
                )
                . " · Fork "
                . number_format(
                    (int) (
                        $row['forks'] ?? 0
                    )
                )
                . " · Issue "
                . number_format(
                    (int) (
                        $row['open_issues']
                        ?? 0
                    )
                )
                . (
                    $languages !== ''
                        ? "\nزبان‌ها: "
                            . $languages
                        : ''
                );

            $url = (string) (
                $row['url'] ?? ''
            );

            if ($url !== '') {
                $message .=
                    "\n\nمخزن: {$url}";
            }

            $context->answer(
                [
                    $this->factory->article(
                        'github',
                        $fullName,
                        "🐙 {$fullName}",
                        $description !== ''
                            ? $description
                            : 'اطلاعات مخزن GitHub',
                        $message,
                        [
                            'thumbnail_url' =>
                                $row[
                                    'owner_avatar'
                                ] ?? null,
                            'url' => $url,
                        ]
                    ),
                ],
                $this->answerOptions()
            );
        } catch (Throwable $exception) {
            $context->answer(
                [
                    $this->factory->error(
                        'github',
                        $this->safeError(
                            $exception
                        )
                    ),
                ],
                [
                    'cache_time' => 1,
                    'is_personal' => true,
                ]
            );
        }
    }

    private function help(
        InlineQueryContext $context,
        string $query
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        $examples = [
            [
                'weather Tehran',
                '🌤 آب‌وهوا',
                'weather Tehran',
            ],
            [
                'calc 2*(8+3)',
                '🧮 ماشین حساب',
                'calc 2*(8+3)',
            ],
            [
                'country Japan',
                '🌍 کشور',
                'country Japan',
            ],
            [
                'currency 100 USD EUR',
                '💱 ارز',
                'currency 100 USD EUR',
            ],
            [
                'wiki PHP',
                '📚 ویکی‌پدیا',
                'wiki PHP',
            ],
            [
                'github php/php-src',
                '🐙 GitHub',
                'github php/php-src',
            ],
        ];

        $results = [];

        foreach ($examples as $example) {
            $results[] =
                $this->factory->article(
                    'inline-help',
                    $example[0],
                    $example[1],
                    $example[2],
                    "برای استفاده در حالت Inline بنویس:\n\n"
                    . '@SmartToolboxFaBot '
                    . $example[2]
                );
        }

        $context->answer(
            $results,
            [
                'cache_time' => 300,
                'is_personal' => true,
            ]
        );
    }

    private function allow(
        InlineQueryContext $context
    ): bool {
        $userId = $context->userId();

        if ($userId === null) {
            $context->answer(
                [],
                [
                    'cache_time' => 1,
                    'is_personal' => true,
                ]
            );

            return false;
        }

        $result = $this->rateLimiter->attempt(
            'inline:' . $userId,
            max(1, $this->maxAttempts),
            max(1, $this->windowSeconds)
        );

        if ($result->allowed) {
            return true;
        }

        $context->answer(
            [
                $this->factory->error(
                    'inline-rate-limit',
                    "درخواست‌ها زیاد است؛ "
                    . "{$result->retryAfter} "
                    . 'ثانیه دیگر تلاش کن.'
                ),
            ],
            [
                'cache_time' => 1,
                'is_personal' => true,
            ]
        );

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function answerOptions(): array
    {
        return [
            'cache_time' => max(
                1,
                min(3600, $this->cacheTime)
            ),
            'is_personal' => true,
        ];
    }

    private function safeMaxResults(): int
    {
        return max(
            1,
            min(10, $this->maxResults)
        );
    }

    private function safeError(
        Throwable $exception
    ): string {
        $message = trim(
            $exception->getMessage()
        );

        return $message !== ''
            ? mb_substr($message, 0, 300)
            : 'خطای موقت در دریافت اطلاعات.'
        ;
    }
}
