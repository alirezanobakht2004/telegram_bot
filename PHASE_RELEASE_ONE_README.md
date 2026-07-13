# Release One: Inline, Profile, Wiki, GitHub, Developer Utilities

این بسته روی «انتشار صفر: زیرساخت نسخه ۲ و Analytics» نصب می‌شود و شامل
فایل‌های جدید و نسخه کامل تمام فایل‌های تغییریافته است.

## قابلیت‌ها

### Inline Mode

- `@SmartToolboxFaBot weather Tehran`
- `@SmartToolboxFaBot calc 2*(8+3)`
- `@SmartToolboxFaBot country Japan`
- `@SmartToolboxFaBot currency 100 USD EUR`
- `@SmartToolboxFaBot wiki PHP`
- `@SmartToolboxFaBot github php/php-src`

نتایج به‌صورت `InlineQueryResultArticle` و `input_message_content` تولید می‌شوند.

### پروفایل، علاقه‌مندی و میان‌بر

- `/favorite weather Tehran`
- `/favorite currency 100 USD EUR`
- `/favorite country Japan`
- `/favorite wiki PHP`
- `/favorite github php/php-src`
- `/favorite calc 2*(8+3)`
- `/favorites`
- `/favoritepin 12`
- `/favoriteunpin 12`
- `/favoritedelete 12`

- `/setshortcut officeweather weather Tehran`
- `/shortcuts`
- `/shortcutdelete officeweather`
- `/officeweather`

- `/history`
- `/clearhistory`
- `/profile`
- `/profilesettings`
- `/setlanguage fa`
- `/setnumberformat persian`
- `/setdateformat iso`
- `/setmenu weather,currency,reminders,wiki,github,developer,profile,tools,settings,animals,help`

تاریخچه به زیرساخت Analytics انتشار صفر متصل است. آرگومان ابزارهای حساس مانند
JWT، Regex، JSON، Hash و Base64 ثبت نمی‌شوند.

### Wikipedia

- `/wiki آلبرت اینشتین`
- `/wiki PHP`
- `/randomwiki`
- `/today`
- `/onthisday`
- `/onthisday 7/20`

جست‌وجو، خلاصه، تصویر، URL، مقاله تصادفی و رویدادهای تاریخی پشتیبانی می‌شوند.
در جست‌وجوی فارسی، در صورت نبود نتیجه، جست‌وجوی انگلیسی نیز بررسی می‌شود.

### GitHub

- `/github owner/repository`
- `/release owner/repository`
- `/issues owner/repository`
- `/watchrelease owner/repository`
- `/unwatchrelease owner/repository`
- `/releasewatches`

اطلاعات مخزن شامل Stars، Forks، Issueهای باز، زبان‌ها، License، Topics و آخرین
Commit است. Release Watchها با Worker عمومی `process_jobs.php` بررسی می‌شوند.

Token اختیاری GitHub فقط باید در `config/local.php` قرار گیرد:

```php
'modules' => [
    'github' => [
        'token' => 'YOUR_OPTIONAL_GITHUB_TOKEN',
    ],
],
```

حالت بدون Token نیز فعال است و Cache برای کاهش مصرف Rate Limit استفاده می‌شود.

### ابزارهای توسعه‌دهندگان

- `/json`
- `/jsonpath`
- `/base64`
- `/base64decode`
- `/urlencode`
- `/urldecode`
- `/jwtdecode`
- `/regex`
- `/uuid`
- `/ulid`
- `/hash`
- `/timestamp`
- `/cron`
- `/color`
- `/ip`
- `/useragent`

فرمان‌های Base64، UUID و Timestamp موجود قبلی حفظ شده‌اند. ابزارهای جدید هیچ
کد PHP، JavaScript یا Shell اجرا نمی‌کنند. JWT فقط Decode می‌شود و اعتبار امضا
تأیید نمی‌شود. Regex دارای محدودیت طول، Backtracking و Recursion است.

## Migration

```text
009_release_one_inline_profile_wiki_github.sql
```

جدول‌های جدید:

- `user_favorites`
- `user_shortcuts`
- `github_release_watches`
- `inline_result_selections`

Migration همچنین Feature Flag زیر را فعال می‌کند:

```text
inline_routing = enabled
rollout_percentage = 100
```

## BotFather

Inline Mode باید یک بار از BotFather فعال شود:

```text
/setinline
```

Placeholder پیشنهادی:

```text
weather Tehran | calc 2*(8+3) | wiki PHP | github php/php-src
```

برای دریافت `chosen_inline_result` و ثبت انتخاب نتیجه در Analytics:

```text
/setinlinefeedback
```

بدون Inline Feedback، خود Inline Mode کار می‌کند؛ فقط آمار انتخاب نتیجه دریافت
نمی‌شود.

## Worker

Scheduled Task عمومی انتشار صفر باید فعال بماند:

```bash
php /home/alirezanobakht2004/telegram_bot/scripts/process_jobs.php
```

همان Worker اکنون Job زیر را نیز پردازش می‌کند:

```text
github.release_watch.scan
```

Task جدیدی لازم نیست.

## تست

```bash
composer dump-autoload -o
composer migrate
composer release-one:test
composer check
composer telegram:set-webhook
composer telegram:webhook-info
composer jobs:process
```

خروجی مورد انتظار تست:

```json
{
    "status": "passed",
    "tests": {
        "jsonpath": true,
        "ulid": true,
        "cron": true,
        "github_repository_parser": true,
        "inline_result_factory": true,
        "database": "passed"
    }
}
```

## Logها

- `storage/logs/developer.log`
- `storage/logs/github.log`
- `storage/logs/webhook.log`
- `storage/logs/jobs.log`

## فایل‌های جدید

- `app/Modules/Profile/ProfileRepository.php`
- `app/Modules/Profile/ProfileModule.php`
- `app/Modules/Wiki/WikiClient.php`
- `app/Modules/Wiki/WikiModule.php`
- `app/Modules/GitHub/GitHubClient.php`
- `app/Modules/GitHub/GitHubWatchRepository.php`
- `app/Modules/GitHub/GitHubReleaseWatchService.php`
- `app/Modules/GitHub/GitHubModule.php`
- `app/Modules/Developer/JsonPathEvaluator.php`
- `app/Modules/Developer/UlidGenerator.php`
- `app/Modules/Developer/CronExpression.php`
- `app/Modules/Developer/DeveloperUtilitiesModule.php`
- `app/Modules/Inline/InlineResultFactory.php`
- `app/Modules/Inline/InlineDataService.php`
- `app/Modules/Inline/InlineSelectionRecorder.php`
- `app/Modules/Inline/InlineModule.php`
- `database/migrations/009_release_one_inline_profile_wiki_github.sql`
- `scripts/test_release_one.php`

## فایل‌های کامل جایگزین

- `app/Core/CommandRouter.php`
- `app/Core/CommandHistory.php`
- `app/Core/CallbackQueryContext.php`
- `app/Core/MessageContext.php`
- `app/Modules/Core/CoreModule.php`
- `app/Web/AdminSettingRegistry.php`
- `config/app.php`
- `public/webhook.php`
- `scripts/process_jobs.php`
- `composer.json`
