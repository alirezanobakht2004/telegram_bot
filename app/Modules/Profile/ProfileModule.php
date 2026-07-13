<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Profile;

use SmartToolbox\Core\CallbackQueryContext;
use SmartToolbox\Core\CallbackRouter;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\RateLimiter;
use SmartToolbox\Core\UserPreferenceStore;
use Throwable;

final class ProfileModule implements ModuleInterface
{
    /**
     * @var list<string>
     */
    private const FAVORITE_TYPES = [
        'weather',
        'currency',
        'country',
        'wiki',
        'github',
        'calc',
    ];

    /**
     * @var list<string>
     */
    private const SHORTCUT_TARGETS = [
        'weather',
        'currency',
        'country',
        'countrycode',
        'wiki',
        'github',
        'release',
        'issues',
        'calc',
        'convert',
        'remind',
        'json',
        'jsonpath',
        'base64',
        'base64decode',
        'urlencode',
        'urldecode',
        'jwtdecode',
        'regex',
        'uuid',
        'ulid',
        'hash',
        'timestamp',
        'cron',
        'color',
        'ip',
        'useragent',
    ];

    private int $shortcutDepth = 0;

    private ?CommandRouter $router = null;

    public function __construct(
        private readonly ProfileRepository $repository,
        private readonly RateLimiter $rateLimiter,
        private readonly UserPreferenceStore $preferences,
        private readonly int $maxFavorites = 50,
        private readonly int $maxShortcuts = 30,
        private readonly int $maxAttempts = 60,
        private readonly int $windowSeconds = 60
    ) {
    }

    public function register(CommandRouter $router): void
    {
        $this->router = $router;

        $router->text(
            '👤 پروفایل',
            function (
                MessageContext $context
            ): void {
                $this->showProfile($context);
            },
            'profile'
        );

        $router->command('favorite', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->addFavorite($context, $arguments);
        });

        $router->command('favorites', function (
            MessageContext $context
        ): void {
            $this->showFavorites($context);
        });

        $router->command('favoritedelete', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->deleteFavorite($context, $arguments);
        });

        $router->command('setshortcut', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->setShortcut($context, $arguments);
        });

        $router->command('shortcuts', function (
            MessageContext $context
        ): void {
            $this->showShortcuts($context);
        });

        $router->command('shortcutdelete', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->deleteShortcut($context, $arguments);
        });

        $router->command('history', function (
            MessageContext $context
        ): void {
            $this->showHistory($context);
        });

        $router->command('clearhistory', function (
            MessageContext $context
        ): void {
            $this->clearHistory($context);
        });

        $router->command('favoritepin', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->setFavoritePinned(
                $context,
                $arguments,
                true
            );
        });

        $router->command('favoriteunpin', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->setFavoritePinned(
                $context,
                $arguments,
                false
            );
        });

        $router->command('setlanguage', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->setPreference(
                $context,
                'output_language',
                $arguments,
                ['fa', 'en'],
                'زبان خروجی',
                '/setlanguage fa'
            );
        });

        $router->command('setnumberformat', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->setPreference(
                $context,
                'number_format',
                $arguments,
                ['latin', 'persian'],
                'قالب عدد',
                '/setnumberformat persian'
            );
        });

        $router->command('setdateformat', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->setPreference(
                $context,
                'date_format',
                $arguments,
                ['iso', 'local'],
                'قالب تاریخ',
                '/setdateformat iso'
            );
        });

        $router->command('setmenu', function (
            MessageContext $context,
            string $arguments
        ): void {
            $this->setMenuOrder(
                $context,
                $arguments
            );
        });

        $router->command('profilesettings', function (
            MessageContext $context
        ): void {
            $this->showProfileSettings(
                $context
            );
        });

        $router->command('profile', function (
            MessageContext $context
        ): void {
            $this->showProfile($context);
        });

        $router->fallbackCommand(
            function (
                MessageContext $context,
                string $name,
                string $arguments
            ) use ($router): bool {
                return $this->executeShortcut(
                    $router,
                    $context,
                    $name,
                    $arguments
                );
            },
            'profile'
        );
    }

    public function registerCallbacks(
        CallbackRouter $router
    ): void {
        $router->on(
            'profile:favorite:run:',
            function (
                CallbackQueryContext $context,
                string $suffix
            ): void {
                $this->runFavoriteCallback(
                    $context,
                    $suffix
                );
            },
            'profile'
        );

        $router->on(
            'profile:favorite:delete:',
            function (
                CallbackQueryContext $context,
                string $suffix
            ): void {
                $this->deleteFavoriteCallback(
                    $context,
                    $suffix
                );
            },
            'profile'
        );
    }

    private function addFavorite(
        MessageContext $context,
        string $arguments
    ): void {
        $userId = $this->privateUser($context);

        if ($userId === null || !$this->allow($context)) {
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
                "فرمت علاقه‌مندی:\n\n"
                . "/favorite weather Tehran\n"
                . "/favorite currency USD EUR\n"
                . "/favorite country Japan\n"
                . "/favorite wiki PHP\n"
                . "/favorite github php/php-src"
            );

            return;
        }

        $type = mb_strtolower($parts[0]);
        $value = trim($parts[1]);

        if (!in_array($type, self::FAVORITE_TYPES, true)) {
            $context->reply(
                'نوع مجاز: weather، currency، country، wiki، github یا calc.'
            );

            return;
        }

        if ($value === '' || mb_strlen($value) > 500) {
            $context->reply('مقدار علاقه‌مندی معتبر نیست.');

            return;
        }

        if (count($this->repository->favorites($userId, 100)) >= $this->safeMaxFavorites()) {
            $context->reply(
                'به سقف علاقه‌مندی‌ها رسیده‌ای. ابتدا یکی را حذف کن.'
            );

            return;
        }

        try {
            $commandText = $type . ' ' . $value;
            $id = $this->repository->addFavorite(
                $userId,
                $type,
                $commandText,
                $this->favoriteLabel($type, $value),
                ['value' => $value]
            );

            $context->reply(
                "علاقه‌مندی ذخیره شد. ⭐\n\n"
                . "🆔 #{$id}\n"
                . '/' . $commandText
                . "\n\nفهرست: /favorites"
            );
        } catch (Throwable $exception) {
            $context->reply(
                'ذخیره علاقه‌مندی با خطا مواجه شد: '
                . $exception->getMessage()
            );
        }
    }

    private function showFavorites(
        MessageContext $context
    ): void {
        $userId = $this->privateUser($context);

        if ($userId === null) {
            return;
        }

        $rows = $this->repository->favorites(
            $userId,
            $this->safeMaxFavorites()
        );

        if ($rows === []) {
            $context->reply(
                "هنوز علاقه‌مندی نداری. ⭐\n\n"
                . 'نمونه: /favorite weather Tehran'
            );

            return;
        }

        $text = "⭐ علاقه‌مندی‌های من\n\n";
        $keyboard = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $label = (string) $row['label'];
            $command = (string) $row['command_text'];
            $pinned = (int) (
                $row['is_pinned'] ?? 0
            ) === 1;

            $text .= "#{$id} — "
                . ($pinned ? '📌 ' : '')
                . "{$label}\n/{$command}\n"
                . (
                    $pinned
                        ? "برداشتن پین: /favoriteunpin {$id}"
                        : "پین: /favoritepin {$id}"
                )
                . "\n\n";
            $keyboard[] = [
                [
                    'text' => '▶️ ' . mb_substr($label, 0, 45),
                    'callback_data' => 'profile:favorite:run:' . $id,
                ],
                [
                    'text' => '🗑',
                    'callback_data' => 'profile:favorite:delete:' . $id,
                ],
            ];
        }

        $context->reply(trim($text), [
            'reply_markup' => [
                'inline_keyboard' => $keyboard,
            ],
        ]);
    }

    private function deleteFavorite(
        MessageContext $context,
        string $arguments
    ): void {
        $userId = $this->privateUser($context);
        $id = $this->positiveId($arguments);

        if ($userId === null) {
            return;
        }

        if ($id === null) {
            $context->reply('نمونه: /favoritedelete 12');
            return;
        }

        $context->reply(
            $this->repository->deleteFavorite($userId, $id)
                ? "علاقه‌مندی #{$id} حذف شد. ✅"
                : 'علاقه‌مندی پیدا نشد.'
        );
    }

    private function setFavoritePinned(
        MessageContext $context,
        string $arguments,
        bool $pinned
    ): void {
        $userId = $this->privateUser($context);
        $id = $this->positiveId($arguments);

        if ($userId === null) {
            return;
        }

        if ($id === null) {
            $context->reply(
                $pinned
                    ? 'نمونه: /favoritepin 12'
                    : 'نمونه: /favoriteunpin 12'
            );
            return;
        }

        $updated =
            $this->repository
                ->setFavoritePinned(
                    $userId,
                    $id,
                    $pinned
                );

        $context->reply(
            $updated
                ? (
                    $pinned
                        ? "علاقه‌مندی #{$id} پین شد. 📌"
                        : "پین علاقه‌مندی #{$id} برداشته شد."
                )
                : 'علاقه‌مندی پیدا نشد.'
        );
    }

    /**
     * @param list<string> $allowed
     */
    private function setPreference(
        MessageContext $context,
        string $key,
        string $arguments,
        array $allowed,
        string $label,
        string $usage
    ): void {
        if ($this->privateUser($context) === null) {
            return;
        }

        $value = mb_strtolower(
            trim($arguments)
        );

        if (!in_array($value, $allowed, true)) {
            $context->reply(
                "{$label} معتبر نیست.\n\n"
                . "مقادیر مجاز: "
                . implode('، ', $allowed)
                . "\n"
                . $usage
            );
            return;
        }

        $this->preferences->set(
            $context->actorKey(),
            $key,
            $value
        );

        $context->reply(
            "{$label} روی «{$value}» تنظیم شد. ✅"
        );
    }

    private function setMenuOrder(
        MessageContext $context,
        string $arguments
    ): void {
        if ($this->privateUser($context) === null) {
            return;
        }

        $allowed = [
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

        $value = str_replace(
            ['،', ';'],
            ',',
            mb_strtolower(
                trim($arguments)
            )
        );

        $items = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'trim',
                        explode(',', $value)
                    )
                )
            )
        );

        if ($items === []) {
            $context->reply(
                "ترتیب منو را با نام‌های انگلیسی و کاما بفرست.\n\n"
                . "نمونه:\n"
                . "/setmenu weather,currency,reminders,alerts,monitoring,file_tools,quiz,wiki,github,developer,profile,tools,settings,animals,help"
            );
            return;
        }

        foreach ($items as $item) {
            if (!in_array($item, $allowed, true)) {
                $context->reply(
                    "گزینه «{$item}» معتبر نیست.\n\n"
                    . 'مقادیر مجاز: '
                    . implode(', ', $allowed)
                );
                return;
            }
        }

        foreach ($allowed as $item) {
            if (!in_array($item, $items, true)) {
                $items[] = $item;
            }
        }

        $this->preferences->set(
            $context->actorKey(),
            'menu_order',
            implode(',', $items)
        );

        $context->reply(
            "ترتیب منو ذخیره شد. ✅\n\n"
            . "برای مشاهده: /menu"
        );
    }

    private function showProfileSettings(
        MessageContext $context
    ): void {
        if ($this->privateUser($context) === null) {
            return;
        }

        $language = $this->preferences->get(
            $context->actorKey(),
            'output_language',
            'fa'
        );

        $numberFormat = $this->preferences->get(
            $context->actorKey(),
            'number_format',
            'latin'
        );

        $dateFormat = $this->preferences->get(
            $context->actorKey(),
            'date_format',
            'iso'
        );

        $menuOrder = $this->preferences->get(
            $context->actorKey(),
            'menu_order',
            'default'
        );

        $context->reply(
            "🎛 تنظیمات پروفایل\n\n"
            . "زبان خروجی: {$language}\n"
            . "قالب عدد: {$numberFormat}\n"
            . "قالب تاریخ: {$dateFormat}\n"
            . "ترتیب منو: {$menuOrder}\n\n"
            . "/setlanguage fa|en\n"
            . "/setnumberformat latin|persian\n"
            . "/setdateformat iso|local\n"
            . "/setmenu weather,currency,wiki,..."
        );
    }

    private function setShortcut(
        MessageContext $context,
        string $arguments
    ): void {
        $userId = $this->privateUser($context);

        if ($userId === null || !$this->allow($context)) {
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
                "فرمت میان‌بر:\n\n"
                . "/setshortcut officeweather weather Tehran\n"
                . "/setshortcut phprepo github php/php-src"
            );
            return;
        }

        $name = mb_strtolower(ltrim($parts[0], '/'));
        $commandText = ltrim(trim($parts[1]), '/');
        $target = mb_strtolower(
            (string) strtok($commandText, " \t\r\n")
        );

        if (!in_array($target, self::SHORTCUT_TARGETS, true)) {
            $context->reply(
                'هدف این میان‌بر مجاز نیست یا ممکن است حلقه ایجاد کند.'
            );
            return;
        }

        if (count($this->repository->shortcuts($userId, 100)) >= $this->safeMaxShortcuts()
            && $this->repository->shortcut($userId, $name) === null
        ) {
            $context->reply('به سقف میان‌برها رسیده‌ای.');
            return;
        }

        try {
            $this->repository->saveShortcut(
                $userId,
                $name,
                $commandText
            );

            $context->reply(
                "میان‌بر ذخیره شد. ✅\n\n"
                . "/{$name}\n"
                . "↳ /{$commandText}"
            );
        } catch (Throwable $exception) {
            $context->reply($exception->getMessage());
        }
    }

    private function showShortcuts(
        MessageContext $context
    ): void {
        $userId = $this->privateUser($context);

        if ($userId === null) {
            return;
        }

        $rows = $this->repository->shortcuts(
            $userId,
            $this->safeMaxShortcuts()
        );

        if ($rows === []) {
            $context->reply(
                "هنوز میان‌بری نداری.\n\n"
                . '/setshortcut officeweather weather Tehran'
            );
            return;
        }

        $text = "⚡ میان‌برهای من\n\n";

        foreach ($rows as $row) {
            $text .= '/'
                . $row['shortcut_name']
                . "\n↳ /"
                . $row['command_text']
                . "\nحذف: /shortcutdelete "
                . $row['shortcut_name']
                . "\n\n";
        }

        $context->reply(trim($text));
    }

    private function deleteShortcut(
        MessageContext $context,
        string $arguments
    ): void {
        $userId = $this->privateUser($context);

        if ($userId === null) {
            return;
        }

        $name = mb_strtolower(ltrim(trim($arguments), '/'));

        if ($name === '') {
            $context->reply('نمونه: /shortcutdelete officeweather');
            return;
        }

        try {
            $deleted = $this->repository->deleteShortcut($userId, $name);
            $context->reply(
                $deleted
                    ? "میان‌بر /{$name} حذف شد. ✅"
                    : 'میان‌بر پیدا نشد.'
            );
        } catch (Throwable $exception) {
            $context->reply($exception->getMessage());
        }
    }

    private function executeShortcut(
        CommandRouter $router,
        MessageContext $context,
        string $name,
        string $arguments
    ): bool {
        $userId = $context->userId;

        if ($userId === null || $this->shortcutDepth > 0) {
            return false;
        }

        try {
            $shortcut = $this->repository->shortcut($userId, $name);
        } catch (Throwable) {
            return false;
        }

        if ($shortcut === null) {
            return false;
        }

        $commandText = trim((string) $shortcut['command_text']);

        if ($arguments !== '') {
            $commandText .= ' ' . $arguments;
        }

        $forwarded = new MessageContext(
            chatId: $context->chatId,
            chatType: $context->chatType,
            userId: $context->userId,
            firstName: $context->firstName,
            text: '/' . $commandText,
            telegram: $this->telegramFrom($context),
            updateContext: $context->updateContext,
            messageId: $context->messageId
        );

        $this->shortcutDepth++;

        try {
            return $router->dispatch($forwarded);
        } finally {
            $this->shortcutDepth--;
        }
    }

    private function showHistory(
        MessageContext $context
    ): void {
        $userId = $this->privateUser($context);

        if ($userId === null) {
            return;
        }

        $rows = $this->repository->history($userId, 20);

        if ($rows === []) {
            $context->reply('تاریخچه دستوری وجود ندارد.');
            return;
        }

        $text = "🕘 تاریخچه آخرین دستورها\n\n";

        foreach ($rows as $row) {
            $status = (int) $row['success'] === 1 ? '✅' : '⚠️';
            $arguments = is_string($row['arguments_preview'] ?? null)
                && $row['arguments_preview'] !== ''
                ? ' ' . $row['arguments_preview']
                : '';

            $text .= $status
                . ' /'
                . $row['command']
                . $arguments
                . "\n"
                . $row['module']
                . ' · '
                . number_format((float) $row['duration_ms'], 1)
                . ' ms · '
                . $row['created_at']
                . "\n\n";
        }

        $context->reply(trim($text));
    }

    private function clearHistory(
        MessageContext $context
    ): void {
        $userId = $this->privateUser($context);

        if ($userId === null) {
            return;
        }

        $deleted = $this->repository->clearHistory($userId);
        $context->reply("{$deleted} رکورد تاریخچه حذف شد. ✅");
    }

    private function showProfile(
        MessageContext $context
    ): void {
        $userId = $this->privateUser($context);

        if ($userId === null) {
            return;
        }

        try {
            $profile = $this->repository->profile($userId);
            $timezone = $this->preferences->get(
                $context->actorKey(),
                'timezone'
            ) ?? 'Asia/Tehran';

            $name = trim(
                (string) ($profile['first_name'] ?? '')
                . ' '
                . (string) ($profile['last_name'] ?? '')
            );

            $context->reply(
                "👤 پروفایل من\n\n"
                . "نام: " . ($name !== '' ? $name : '—') . "\n"
                . "شناسه: {$userId}\n"
                . "نام کاربری: "
                . (($profile['username'] ?? '') !== ''
                    ? '@' . $profile['username']
                    : '—')
                . "\nزبان تلگرام: "
                . (($profile['language_code'] ?? '') ?: '—')
                . "\nمنطقه زمانی: {$timezone}\n"
                . "زبان خروجی: "
                . (
                    $this->preferences->get(
                        $context->actorKey(),
                        'output_language',
                        'fa'
                    )
                )
                . "\nقالب عدد: "
                . (
                    $this->preferences->get(
                        $context->actorKey(),
                        'number_format',
                        'latin'
                    )
                )
                . "\nقالب تاریخ: "
                . (
                    $this->preferences->get(
                        $context->actorKey(),
                        'date_format',
                        'iso'
                    )
                )
                . "\n\n"
                . "⭐ علاقه‌مندی‌ها: {$profile['favorites_count']}\n"
                . "⚡ میان‌برها: {$profile['shortcuts_count']}\n"
                . "🕘 تاریخچه: {$profile['history_count']}\n"
                . "⏰ یادآور فعال: {$profile['reminders_count']}\n"
                . "📨 کل درخواست‌ها: {$profile['request_count']}\n\n"
                . "اولین حضور: {$profile['first_seen_at']}\n"
                . "آخرین حضور: {$profile['last_seen_at']}"
            );
        } catch (Throwable $exception) {
            $context->reply(
                'نمایش پروفایل ممکن نشد: ' . $exception->getMessage()
            );
        }
    }

    private function runFavoriteCallback(
        CallbackQueryContext $context,
        string $suffix
    ): void {
        $userId = $context->userId();
        $id = $this->positiveId($suffix);

        if ($userId === null || $id === null) {
            $context->answer('درخواست نامعتبر است.', true);
            return;
        }

        $favorite = $this->repository->favorite($userId, $id);

        if ($favorite === null) {
            $context->answer('علاقه‌مندی پیدا نشد.', true);
            return;
        }

        if (
            $this->router === null
            || $context->chatId() === null
        ) {
            $context->answer(
                'اجرای مستقیم ممکن نیست.',
                true
            );
            return;
        }

        $context->answer(
            'در حال اجرا…'
        );

        $messageContext = new MessageContext(
            chatId: $context->chatId(),
            chatType: $context->chatType(),
            userId: $userId,
            firstName: $context->firstName(),
            text: '/'
                . (string) $favorite[
                    'command_text'
                ],
            telegram: $context->telegram(),
            updateContext:
                $context->updateContext,
            messageId: $context->messageId()
        );

        $this->router->dispatch(
            $messageContext
        );
    }

    private function deleteFavoriteCallback(
        CallbackQueryContext $context,
        string $suffix
    ): void {
        $userId = $context->userId();
        $id = $this->positiveId($suffix);

        if ($userId === null || $id === null) {
            $context->answer('درخواست نامعتبر است.', true);
            return;
        }

        $deleted = $this->repository->deleteFavorite($userId, $id);
        $context->answer(
            $deleted ? 'حذف شد.' : 'پیدا نشد.',
            !$deleted
        );
    }

    private function privateUser(
        MessageContext $context
    ): ?int {
        if (!$context->isPrivate()) {
            $context->reply(
                'برای حفظ حریم خصوصی، این قابلیت فقط در چت خصوصی ربات فعال است.'
            );
            return null;
        }

        if ($context->userId === null) {
            $context->reply('شناسه کاربر در دسترس نیست.');
            return null;
        }

        return $context->userId;
    }

    private function allow(MessageContext $context): bool
    {
        $result = $this->rateLimiter->attempt(
            'profile:' . $context->actorKey(),
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

    private function favoriteLabel(
        string $type,
        string $value
    ): string {
        $emoji = match ($type) {
            'weather' => '🌤',
            'currency' => '💱',
            'country' => '🌍',
            'wiki' => '📚',
            'github' => '🐙',
            'calc' => '🧮',
            default => '⭐',
        };

        return $emoji . ' ' . mb_substr($value, 0, 150);
    }

    private function positiveId(string $value): ?int
    {
        $value = trim($value);

        if (preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function safeMaxFavorites(): int
    {
        return max(1, min(200, $this->maxFavorites));
    }

    private function safeMaxShortcuts(): int
    {
        return max(1, min(100, $this->maxShortcuts));
    }

    /**
     * MessageContext intentionally hides TelegramClient. Reflection is not
     * acceptable, so shortcut execution is delegated through a clone helper
     * added by this release.
     */
    private function telegramFrom(
        MessageContext $context
    ): \SmartToolbox\Core\TelegramClient {
        return $context->telegram();
    }
}
