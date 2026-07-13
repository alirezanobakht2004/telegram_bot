<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Core;

use JsonException;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\UserPreferenceStore;

final class CoreModule implements ModuleInterface
{
    public function __construct(
        private readonly ?UserPreferenceStore $preferences = null
    ) {
    }

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

    private function start(
        MessageContext $context
    ): void {
        $name = trim($context->firstName);
        $english = $this->english($context);

        $greeting = $english
            ? (
                $name !== ''
                    ? "Hello {$name} 👋"
                    : 'Hello 👋'
            )
            : (
                $name !== ''
                    ? "سلام {$name} 👋"
                    : 'سلام 👋'
            );

        $options = [];

        if ($context->isPrivate()) {
            $options['reply_markup'] =
                $this->mainKeyboard($context);
        }

        $context->reply(
            $greeting . "\n\n"
            . (
                $english
                    ? "Welcome to Smart Toolbox.\n\nChoose a tool from the menu."
                    : "به جعبه ابزار خوش آمدی.\n\nیکی از ابزارهای منو را انتخاب کن."
            ),
            $options
        );
    }

    private function menu(
        MessageContext $context
    ): void {
        $options = [];

        if ($context->isPrivate()) {
            $options['reply_markup'] =
                $this->mainKeyboard($context);
        }

        $english = $this->english($context);

        $context->reply(
            $english
                ? "🧰 Smart Toolbox\n\n"
                    . "Weather, countries, currencies, reminders, calculator, Wikipedia, GitHub, developer utilities, favorites, shortcuts and personal settings."
                : "🧰 منوی اصلی جعبه ابزار\n\n"
                    . "🌤 آب‌وهوا و پیش‌بینی\n"
                    . "🌍 اطلاعات کشورها\n"
                    . "💱 تبدیل ارز رسمی\n"
                    . "⏰ یادآورها\n"
                    . "🔔 هشدارها و اشتراک‌ها\n"
                    . "📡 مانیتورینگ سایت\n"
                    . "🎯 مسابقه، آزمون و امتیاز\n"
                    . "🧮 ماشین حساب و تبدیل واحد\n"
                    . "📚 ویکی‌پدیا\n"
                    . "🐙 GitHub و Release Watch\n"
                    . "🧑‍💻 ابزارهای توسعه‌دهندگان\n"
                    . "👤 پروفایل، علاقه‌مندی و میان‌بر\n"
                    . "⚙️ تنظیمات شخصی",
            $options
        );
    }

    private function help(
        MessageContext $context
    ): void {
        $context->reply(
            "ℹ️ راهنمای جعبه ابزار\n\n"
            . "/weather Tehran — آب‌وهوا\n"
            . "/country Iran — کشور\n"
            . "/currency 100 USD EUR — ارز\n"
            . "/remind 10m خرید شیر — یادآور\n"
            . "/calc 2*(3+4) — ماشین حساب\n"
            . "/wiki PHP — ویکی‌پدیا\n"
            . "/randomwiki — مقاله تصادفی\n"
            . "/today — رویدادهای امروز\n"
            . "/github php/php-src — مخزن GitHub\n"
            . "/release owner/repo — آخرین Release\n"
            . "/issues owner/repo — Issueهای باز\n"
            . "/watchrelease owner/repo — هشدار Release\n"
            . "/alert weather Tehran rain — هشدار هوشمند\n"
            . "/subscribe weather Tehran daily 08:00 — اشتراک\n"
            . "/monitor https://example.com 5m — مانیتور سایت\n"
            . "/status https://example.com — بررسی فوری\n"
            . "/ssl example.com، /dns example.com، /headers URL\n\n"
            . "مدیریت گروه:\n"
            . "/groupadmin — وضعیت و راهنما\n"
            . "/warn، /warnings، /unwarn\n"
            . "/mute، /unmute، /ban، /unban، /kick\n"
            . "/purge، /slowmode، /rules، /setrules\n"
            . "/antispam، /antilink، /badwords، /captcha\n"
            . "/invitelink، /revokelink، /joinrequests\n\n"
            . "مسابقه و امتیاز:\n"
            . "/quiz، /trivia، /mathgame، /wordgame\n"
            . "/dailychallenge، /leaderboard\n"
            . "/myscore، /achievements، /streak\n\n"
            . "/favorite weather Tehran — علاقه‌مندی\n"
            . "/favorites — فهرست علاقه‌مندی‌ها\n"
            . "/setshortcut office weather Tehran — میان‌بر\n"
            . "/shortcuts — فهرست میان‌برها\n"
            . "/history — تاریخچه دستورها\n"
            . "/profile — پروفایل\n"
            . "/profilesettings — تنظیمات پروفایل\n\n"
            . "/json، /jsonpath، /regex\n"
            . "/base64، /base64decode\n"
            . "/urlencode، /urldecode\n"
            . "/jwtdecode، /hash\n"
            . "/uuid، /ulid، /timestamp\n"
            . "/cron، /color، /ip، /useragent\n\n"
            . "Inline:\n"
            . "@SmartToolboxFaBot weather Tehran\n"
            . "@SmartToolboxFaBot calc 2*(8+3)\n"
            . "@SmartToolboxFaBot wiki PHP\n"
            . "@SmartToolboxFaBot github php/php-src"
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mainKeyboard(
        MessageContext $context
    ): array {
        $order = $this->menuOrder($context);
        $rows = [];
        $buffer = [];

        foreach ($order as $item) {
            if ($item === 'animals') {
                if ($buffer !== []) {
                    $rows[] = $buffer;
                    $buffer = [];
                }

                $rows[] = [
                    ['text' => '🐶 سگ'],
                    ['text' => '🐱 گربه'],
                    ['text' => '🦊 روباه'],
                ];
                continue;
            }

            $button = $this->menuButton($item);

            if ($button === null) {
                continue;
            }

            $buffer[] = $button;

            if (count($buffer) === 2) {
                $rows[] = $buffer;
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $rows[] = $buffer;
        }

        return [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'one_time_keyboard' => false,
            'input_field_placeholder' =>
                'یکی از ابزارها را انتخاب کن',
        ];
    }

    /**
     * @return list<string>
     */
    private function menuOrder(
        MessageContext $context
    ): array {
        $default = [
            'weather',
            'countries',
            'currency',
            'reminders',
            'alerts',
            'monitoring',
            'file_tools',
            'quiz',
            'calculator',
            'tools',
            'developer',
            'wiki',
            'github',
            'profile',
            'settings',
            'animals',
            'help',
        ];

        if ($this->preferences === null) {
            return $default;
        }

        $stored = $this->preferences->get(
            $context->actorKey(),
            'menu_order'
        );

        if ($stored === null || trim($stored) === '') {
            return $default;
        }

        $items = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'trim',
                        explode(',', $stored)
                    )
                )
            )
        );

        foreach ($default as $item) {
            if (!in_array($item, $items, true)) {
                $items[] = $item;
            }
        }

        return array_values(
            array_filter(
                $items,
                fn (string $item): bool =>
                    in_array($item, $default, true)
            )
        );
    }

    /**
     * @return array{text: string}|null
     */
    private function menuButton(
        string $item
    ): ?array {
        $text = match ($item) {
            'weather' => '🌤 آب‌وهوا',
            'countries' => '🌍 کشورها',
            'currency' => '💱 نرخ ارز',
            'reminders' => '⏰ یادآورها',
            'alerts' => '🔔 هشدارها',
            'monitoring' => '📡 مانیتورینگ',
            'file_tools' => '📁 فایل و تصویر',
            'quiz' => '🎯 مسابقه و آزمون',
            'calculator' => '🧮 ماشین حساب',
            'tools' => '🧰 ابزارها',
            'developer' => '🧑‍💻 توسعه‌دهنده',
            'wiki' => '📚 ویکی‌پدیا',
            'github' => '🐙 GitHub',
            'profile' => '👤 پروفایل',
            'settings' => '⚙️ تنظیمات',
            'help' => 'ℹ️ راهنما',
            default => null,
        };

        return $text !== null
            ? ['text' => $text]
            : null;
    }

    private function english(
        MessageContext $context
    ): bool {
        return $this->preferences?->get(
            $context->actorKey(),
            'output_language',
            'fa'
        ) === 'en';
    }
}
