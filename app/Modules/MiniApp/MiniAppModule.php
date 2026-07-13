<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\MiniApp;

use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;

final class MiniAppModule implements ModuleInterface
{
    public function __construct(
        private readonly string $url,
        private readonly string $botUsername
    ) {
    }

    public function register(
        CommandRouter $router
    ): void {
        foreach (['app', 'miniapp'] as $command) {
            $router->command(
                $command,
                function (
                    MessageContext $context
                ): void {
                    $this->open($context);
                },
                'mini_app'
            );
        }

        $router->text(
            '📱 اپ کاربران',
            function (
                MessageContext $context
            ): void {
                $this->open($context);
            },
            'mini_app'
        );
    }

    private function open(
        MessageContext $context
    ): void {
        if (!$context->isPrivate()) {
            $username = ltrim(
                trim($this->botUsername),
                '@'
            );

            $context->reply(
                "Mini App از چت خصوصی ربات باز می‌شود. 📱\n\n"
                . "https://t.me/{$username}?startapp=dashboard"
            );

            return;
        }

        $url = trim($this->url);

        if (
            preg_match(
                '#^https://[^\s]+$#i',
                $url
            ) !== 1
        ) {
            $context->reply(
                'آدرس Mini App هنوز به‌درستی تنظیم نشده است.'
            );

            return;
        }

        $context->reply(
            "📱 Mini App جعبه ابزار\n\n"
            . "داشبورد، یادآورها، هشدارها، مانیتورها، علاقه‌مندی‌ها، امتیاز آزمون و تنظیمات شخصی در یک رابط گرافیکی.",
            [
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => 'بازکردن Mini App',
                                'web_app' => [
                                    'url' => $url,
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }
}
