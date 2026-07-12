<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Animals;

use RuntimeException;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\FileCache;
use SmartToolbox\Core\HttpClient;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\RateLimiter;
use Throwable;

final class AnimalsModule implements ModuleInterface
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly FileCache $cache,
        private readonly RateLimiter $rateLimiter,
        private readonly string $dogEndpoint,
        private readonly string $catEndpoint,
        private readonly string $foxEndpoint,
        private readonly string $logFile,
        private readonly int $cacheTtl = 5,
        private readonly int $maxAttempts = 30,
        private readonly int $windowSeconds = 60
    ) {
    }

    public function register(CommandRouter $router): void
    {
        $router->command(
            'dog',
            function (MessageContext $context): void {
                $this->sendAnimal($context, 'dog');
            }
        );

        $router->command(
            'cat',
            function (MessageContext $context): void {
                $this->sendAnimal($context, 'cat');
            }
        );

        $router->command(
            'fox',
            function (MessageContext $context): void {
                $this->sendAnimal($context, 'fox');
            }
        );

        $router->text(
            '🐶 سگ',
            function (MessageContext $context): void {
                $this->sendAnimal($context, 'dog');
            }
        );

        $router->text(
            '🐱 گربه',
            function (MessageContext $context): void {
                $this->sendAnimal($context, 'cat');
            }
        );

        $router->text(
            '🦊 روباه',
            function (MessageContext $context): void {
                $this->sendAnimal($context, 'fox');
            }
        );
    }

    private function sendAnimal(
        MessageContext $context,
        string $animal
    ): void {
        $rateLimit = $this->rateLimiter->attempt(
            'animals:' . $context->actorKey(),
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
            $imageUrl = $this->imageUrl($animal);
        } catch (Throwable $exception) {
            $this->log(
                $animal,
                $exception
            );

            $context->reply(
                "فعلاً دریافت تصویر ممکن نشد. ⚠️\n\n"
                . 'چند لحظه بعد دوباره امتحان کن.'
            );

            return;
        }

        try {
            $context->replyWithPhoto(
                $imageUrl,
                $this->caption($animal)
            );
        } catch (Throwable $exception) {
            $this->log(
                $animal . ':sendPhoto',
                $exception
            );

            $context->reply(
                "تصویر آماده شد، اما تلگرام نتوانست آن را "
                . "مستقیم دریافت کند:\n\n"
                . $imageUrl
            );
        }
    }

    private function imageUrl(string $animal): string
    {
        $url = $this->cache->remember(
            'animals.random.' . $animal,
            $this->cacheTtl,
            fn (): string => match ($animal) {
                'dog' => $this->fetchDog(),
                'cat' => $this->fetchCat(),
                'fox' => $this->fetchFox(),

                default => throw new RuntimeException(
                    'Unsupported animal provider.'
                ),
            }
        );

        if (!is_string($url)) {
            throw new RuntimeException(
                'Cached animal URL is invalid.'
            );
        }

        return $this->validateImageUrl($url);
    }

    private function fetchDog(): string
    {
        $data = $this->http
            ->get($this->dogEndpoint)
            ->requireSuccess()
            ->jsonArray();

        if (
            ($data['status'] ?? null) !== 'success'
            || !is_string($data['message'] ?? null)
        ) {
            throw new RuntimeException(
                'Dog provider returned an unexpected response.'
            );
        }

        return $this->validateImageUrl(
            $data['message']
        );
    }

    private function fetchCat(): string
    {
        $separator = str_contains(
            $this->catEndpoint,
            '?'
        )
            ? '&'
            : '?';

        /*
         * در CATAAS پارامتر type برای اندازه تصویر است،
         * نه فرمت فایل.
         *
         * type=jpg نامعتبر است.
         * type=medium یک مقدار معتبر است.
         */
        $requestUrl = $this->catEndpoint
            . $separator
            . 'json=true&type=medium';

        $data = $this->http
            ->get($requestUrl)
            ->requireSuccess()
            ->jsonArray();

        $url = $data['url'] ?? null;

        if (
            is_string($url)
            && trim($url) !== ''
        ) {
            if (str_starts_with($url, '/')) {
                $url = 'https://cataas.com' . $url;
            }

            return $this->validateImageUrl(
                $url
            );
        }

        /*
         * برای سازگاری با نسخه‌هایی از API که فقط ID
         * تصویر را برمی‌گردانند.
         */
        $id = $data['id']
            ?? $data['_id']
            ?? null;

        if (
            !is_string($id)
            || trim($id) === ''
        ) {
            throw new RuntimeException(
                'Cat provider returned an unexpected response.'
            );
        }

        return $this->validateImageUrl(
            'https://cataas.com/cat/'
            . rawurlencode(trim($id))
            . '?type=medium&position=center'
        );
    }

    private function fetchFox(): string
    {
        $data = $this->http
            ->get($this->foxEndpoint)
            ->requireSuccess()
            ->jsonArray();

        $image = $data['image'] ?? null;

        if (!is_string($image)) {
            throw new RuntimeException(
                'Fox provider returned an unexpected response.'
            );
        }

        return $this->validateImageUrl(
            $image
        );
    }

    private function validateImageUrl(
        string $url
    ): string {
        $url = trim($url);

        if (
            $url === ''
            || !filter_var(
                $url,
                FILTER_VALIDATE_URL
            )
        ) {
            throw new RuntimeException(
                'Animal provider returned an invalid image URL.'
            );
        }

        $scheme = mb_strtolower(
            (string) parse_url(
                $url,
                PHP_URL_SCHEME
            )
        );

        if ($scheme !== 'https') {
            throw new RuntimeException(
                'Animal image URL must use HTTPS.'
            );
        }

        return $url;
    }

    private function log(
        string $context,
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
            "[%s] [%s] %s\n",
            date(DATE_ATOM),
            $context,
            $exception->getMessage()
        );

        @file_put_contents(
            $this->logFile,
            $entry,
            FILE_APPEND | LOCK_EX
        );
    }

    private function caption(
        string $animal
    ): string {
        return match ($animal) {
            'dog' =>
                "🐶 سگ تصادفی\n\n"
                . 'برای تصویر بعدی: /dog',

            'cat' =>
                "🐱 گربه تصادفی\n\n"
                . 'برای تصویر بعدی: /cat',

            'fox' =>
                "🦊 روباه تصادفی\n\n"
                . 'برای تصویر بعدی: /fox',

            default =>
                'تصویر تصادفی',
        };
    }
}