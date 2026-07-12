<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Core;

use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;

final class CoreModule implements ModuleInterface
{
    public function register(CommandRouter $router): void
    {
        $router->command(
            'start',
            function (MessageContext $context): void {
                $this->start($context);
            }
        );

        $router->command(
            'menu',
            function (MessageContext $context): void {
                $this->menu($context);
            }
        );

        $router->command(
            'help',
            function (MessageContext $context): void {
                $this->help($context);
            }
        );

        $router->text(
            '🏠 منوی اصلی',
            function (MessageContext $context): void {
                $this->menu($context);
            }
        );

        $router->text(
            'ℹ️ راهنما',
            function (MessageContext $context): void {
                $this->help($context);
            }
        );

        foreach ($this->comingSoonButtons() as $button) {
            $router->text(
                $button,
                function (MessageContext $context): void {
                    $this->comingSoon($context);
                }
            );
        }

        $router->unknownCommand(
            static function (
                MessageContext $context,
                string $command
            ): void {
                $context->reply(
                    "دستور /{$command} شناخته نشد.\n\n"
                    . 'برای مشاهده دستورات از /help استفاده کن.'
                );
            }
        );
    }

    private function start(MessageContext $context): void
    {
        $name = trim($context->firstName);

        $greeting = $name !== ''
            ? "سلام {$name} 👋"
            : 'سلام 👋';

        $options = [];

        if ($context->isPrivate()) {
            $options['reply_markup'] = $this->mainKeyboard();
        }

        $context->reply(
            $greeting . "\n\n"
            . "به جعبه ابزار خوش آمدی.\n\n"
            . "اینجا به ابزارهای اطلاعاتی، کاربردی و سرگرمی "
            . "دسترسی خواهی داشت.\n\n"
            . "برای شروع یکی از گزینه‌های منو را انتخاب کن.",
            $options
        );
    }

    private function menu(MessageContext $context): void
    {
        $options = [];

        if ($context->isPrivate()) {
            $options['reply_markup'] = $this->mainKeyboard();
        }

        $context->reply(
            "🧰 منوی اصلی جعبه ابزار\n\n"
            . "🌤 آب‌وهوا و پیش‌بینی چندروزه\n"
            . "🌍 اطلاعات کشورها\n"
            . "💱 نرخ و تبدیل ارزهای رسمی\n"
            . "🧰 ابزارهای داخلی و رایگان\n"
            . "🐶 تصویر تصادفی سگ\n"
            . "🐱 تصویر تصادفی گربه\n"
            . "🦊 تصویر تصادفی روباه\n"
            . "⚙️ تنظیمات شخصی\n"
            . "ℹ️ راهنمای ربات",
            $options
        );
    }

    private function help(MessageContext $context): void
    {
        $context->reply(
            "ℹ️ راهنمای جعبه ابزار\n\n"
            . "/start — شروع ربات\n"
            . "/menu — نمایش منوی اصلی\n"
            . "/help — نمایش راهنما\n"
            . "/weather Tehran — آب‌وهوای یک شهر\n"
            . "/country Iran — اطلاعات یک کشور\n"
            . "/randomcountry — کشور تصادفی\n"
            . "/currency 100 USD EUR — تبدیل ارز\n"
            . "/tools — ابزارهای داخلی\n"
            . "/password 24 — رمز تصادفی\n"
            . "/uuid — UUID نسخه 4\n"
            . "/sha256 hello — هش SHA-256\n"
            . "/base64 hello — تبدیل Base64\n"
            . "/count متن — شمارش متن\n"
            . "/random 1 100 — عدد تصادفی\n"
            . "/coin — شیر یا خط\n"
            . "/timestamp — زمان یونیکس\n"
            . "/settings — تنظیمات شخصی\n"
            . "/settimezone Asia/Tehran — منطقه زمانی\n"
            . "/setpasswordlength 24 — طول پیش‌فرض رمز\n"
            . "/dog — دریافت تصویر سگ\n"
            . "/cat — دریافت تصویر گربه\n"
            . "/fox — دریافت تصویر روباه\n"
            . "/cancel — لغو عملیات مرحله‌ای\n\n"
            . "در چت خصوصی می‌توانی از دکمه‌های منو "
            . "هم استفاده کنی."
        );
    }

    private function comingSoon(MessageContext $context): void
    {
        $context->reply(
            "این بخش در حال آماده‌سازی است. 🛠\n\n"
            . 'در مرحله بعد به قابلیت واقعی متصل می‌شود.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mainKeyboard(): array
    {
        return [
            'keyboard' => [
                [
                    [
                        'text' => '🌤 آب‌وهوا',
                    ],
                    [
                        'text' => '🌍 کشورها',
                    ],
                ],
                [
                    [
                        'text' => '💱 نرخ ارز',
                    ],
                    [
                        'text' => '🧰 ابزارها',
                    ],
                ],
                [
                    [
                        'text' => '🐶 سگ',
                    ],
                    [
                        'text' => '🐱 گربه',
                    ],
                    [
                        'text' => '🦊 روباه',
                    ],
                ],
                [
                    [
                        'text' => '⚙️ تنظیمات',
                    ],
                    [
                        'text' => 'ℹ️ راهنما',
                    ],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
            'input_field_placeholder' =>
                'یکی از ابزارها را انتخاب کن',
        ];
    }

    /**
     * @return list<string>
     */
    private function comingSoonButtons(): array
    {
        return [];
    }
}
