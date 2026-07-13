# انتشار شش: Mini App کاربران

این بسته روی انتشار پنج نصب می‌شود و داشبورد گرافیکی کاربران را در مسیر زیر
فعال می‌کند:

```text
https://alirezanobakht2004.alwaysdata.net/app/
```

Mini App حساب و رمز جدا ندارد. Backend فقط `Telegram.WebApp.initData` امضاشده
را می‌پذیرد، شناسه کاربر را از داده اعتبارسنجی‌شده استخراج می‌کند و سپس یک
Session کوتاه‌مدت ایجاد می‌کند.

## صفحات کاربران

```text
داشبورد
یادآورهای تقویمی
هشدارها
اشتراک‌ها
مانیتورها
علاقه‌مندی‌ها
میان‌برها
شهرهای منتخب
ارزهای منتخب
کشورهای ذخیره‌شده
تاریخچه دستورات
آزمون‌ها و امتیاز
تنظیمات شخصی
```

## دستورات ربات

```text
/app
/miniapp
```

دکمه صفحه‌کلید:

```text
📱 اپ کاربران
```

در چت خصوصی یک دکمه `web_app` ارسال می‌شود. در گروه، لینک ورود به چت خصوصی
ربات با `startapp=dashboard` نمایش داده می‌شود.

## امنیت احراز هویت

فرایند ورود:

```text
Telegram.WebApp.initData
→ Parse بدون پذیرش کلید تکراری
→ حذف hash از Data Check String
→ مرتب‌سازی کلیدها
→ HMAC-SHA-256
→ بررسی hash با hash_equals
→ بررسی auth_date
→ استخراج user_id فقط از داده امضاشده
→ ساخت Session تصادفی
```

کلید HMAC مطابق روش Telegram از Bot Token مشتق می‌شود:

```text
secret_key = HMAC_SHA256(bot_token, key="WebAppData")
expected_hash = HMAC_SHA256(data_check_string, secret_key)
```

کنترل‌های امنیتی:

```text
حداکثر عمر initData: 300 ثانیه
تلورانس زمان آینده: 30 ثانیه
Session Idle TTL: 1200 ثانیه
Session Absolute TTL: 21600 ثانیه
حداکثر Session فعال هر کاربر: 5
Session Token تصادفی 256 بیتی
CSRF Token تصادفی 256 بیتی
ذخیره فقط Hash Session و CSRF
Cookie: Secure + HttpOnly + SameSite=Strict
اتصال Session به User-Agent
عدم اعتماد به user_id ارسالی JavaScript
Same-Origin Origin Check برای POST
JSON-only API
محدودیت حجم درخواست
Rate Limit مستقل Auth و API
Audit با حذف Secretها
CSP و Security Headerها
عدم نمایش Bot Token
```

نام Cookie پیش‌فرض:

```text
__Secure-smarttoolbox-mini
```

## API

مسیر:

```text
/app/api.php?action=<action>
```

عملیات خواندنی فقط `GET` و عملیات تغییردهنده فقط `POST application/json`
هستند. تمام POSTهای Sessionدار به Header زیر نیاز دارند:

```text
X-CSRF-Token
```

Actionهای اصلی:

```text
auth
logout
dashboard
reminders
alerts
subscriptions
monitors
favorites
shortcuts
cities
currencies
countries
history
quiz
settings

reminder.create
reminder.cancel
reminder.delete
alert.create
alert.status
subscription.create
subscription.status
monitor.create
monitor.status
favorite.create
favorite.pin
favorite.delete
city.save
currency.save
country.save
shortcut.save
shortcut.delete
history.clear
settings.update
```

همه Queryها با `user_id` استخراج‌شده از Session محدود می‌شوند.

## پنل مدیریت

مسیر:

```text
/admin/?section=mini_app
```

امکانات:

```text
Sessionهای فعال
تعداد کاربران Sessionدار
ورودهای امروز
درخواست‌های امروز
خطاهای امروز
Rate Limitهای فعال
مشاهده آخرین Sessionها
لغو یک Session
لغو تمام Sessionهای یک کاربر
Audit کامل Mini App
اجرای دستی Cleanup
```

Session Token، CSRF Token و Bot Token در پنل نمایش داده نمی‌شوند.

## Worker

Job جدید:

```text
mini_app.maintenance
```

وظایف:

```text
حذف Sessionهای منقضی قدیمی
حذف Rate Limitهای منقضی
حذف Audit بر اساس Retention
```

Scheduled Task جدید لازم نیست. همان Worker عمومی اجرا می‌شود:

```bash
php /home/alirezanobakht2004/telegram_bot/scripts/process_jobs.php
```

## Migration

```text
database/migrations/014_release_six_mini_app.sql
```

جدول‌ها:

```text
mini_app_sessions
mini_app_rate_limits
mini_app_audit_logs
```

Feature Flag:

```text
mini_app = enabled
rollout_percentage = 100
```

## تنظیمات Runtime

```text
modules.mini_app.enabled
modules.mini_app.url
modules.mini_app.retention_days
modules.mini_app.audit_retention_days

modules.mini_app.security.cookie_name
modules.mini_app.security.init_data_max_age_seconds
modules.mini_app.security.auth_date_future_skew_seconds
modules.mini_app.security.max_init_data_bytes
modules.mini_app.security.max_request_bytes
modules.mini_app.security.session_idle_ttl_seconds
modules.mini_app.security.session_absolute_ttl_seconds
modules.mini_app.security.max_active_sessions_per_user

modules.mini_app.rate_limit.auth_max_attempts
modules.mini_app.rate_limit.auth_window_seconds
modules.mini_app.rate_limit.api_max_attempts
modules.mini_app.rate_limit.api_window_seconds

modules.mini_app.worker.interval_seconds
```

آدرس و نام Cookie بهتر است فقط در `config/local.php` Override شوند. Bot Token
همچنان فقط در فایل Secret و خارج از Git باقی می‌ماند.

## تنظیم Menu Button با Bot API

Script جدید:

```bash
composer telegram:set-menu-button
```

این Script متد `setChatMenuButton` را با مشخصات زیر اجرا می‌کند:

```text
Type: web_app
Text: بازکردن اپ
URL: https://alirezanobakht2004.alwaysdata.net/app/
```

## تنظیمات BotFather

برای تکمیل انتشار:

1. ربات `@SmartToolboxFaBot` را در BotFather انتخاب کن.
2. Inline Mode را فعال و Placeholder را تنظیم کن؛ این مورد از انتشار یک
   استفاده می‌شود.
3. در Bot Settings، Menu Button یا Main Mini App را روی این URL قرار بده:

```text
https://alirezanobakht2004.alwaysdata.net/app/
```

4. Domain مجاز Mini App را روی دامنه زیر ثبت کن:

```text
alirezanobakht2004.alwaysdata.net
```

5. برای مدیریت گروه‌ها، ربات همچنان باید Administrator و دارای حقوق لازم
   باشد.

Mini App به Update Type تازه‌ای نیاز ندارد. `allowed_updates` نهایی پروژه:

```json
[
  "message",
  "edited_message",
  "inline_query",
  "chosen_inline_result",
  "callback_query",
  "poll",
  "poll_answer",
  "my_chat_member",
  "chat_member",
  "chat_join_request"
]
```

## نصب محلی

محتویات ZIP را داخل پروژه Copy و Replace کن:

```text
G:\Projects\telegram_bot
```

سپس:

```bat
cd /d G:\Projects\telegram_bot

composer dump-autoload -o
composer migrate
composer release-six:test
composer check
composer jobs:process
git status --short
```

خروجی Migration:

```text
[APPLIED] 014_release_six_mini_app.sql
Migration completed. New migrations: 1
```

خروجی تست:

```json
{
    "status": "passed",
    "tests": {
        "init_data_signature": true,
        "init_data_expiry": true,
        "tamper_rejection": true,
        "session_authentication": true,
        "csrf": true,
        "session_context_binding": true,
        "rate_limit": true,
        "audit_redaction": true,
        "dashboard_repository": true,
        "database": "passed"
    }
}
```

Commit:

```bat
git add .
git commit -m "feat: add authenticated Telegram Mini App"
git push
```

## استقرار Alwaysdata

```bash
cd "$HOME/telegram_bot"

git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
composer migrate
composer release-six:test
composer check
composer jobs:process
composer telegram:set-menu-button
composer telegram:webhook-info
```

سپس URL زیر را باز کن:

```text
https://alirezanobakht2004.alwaysdata.net/app/
```

بازکردن مستقیم در مرورگر باید پیام «Mini App باید از داخل Telegram باز شود»
نمایش دهد؛ ورود موفق فقط از داخل Telegram انجام می‌شود.

## تست Telegram

```text
/app
/miniapp
```

سپس در Mini App این عملیات را بررسی کن:

```text
ساخت و لغو یادآور
ساخت و Pause هشدار
ساخت اشتراک
ساخت مانیتور
ذخیره شهر، ارز و کشور
ساخت و Pin علاقه‌مندی
ساخت و حذف میان‌بر
پاک‌کردن تاریخچه
مشاهده امتیاز و Achievement
تغییر تنظیمات شخصی
Logout و ورود مجدد
```

## بررسی دیتابیس

```bash
php -r '
$pdo = new PDO("sqlite:" . getcwd() . "/storage/bot.sqlite");
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

foreach ([
    "mini_app_sessions",
    "mini_app_rate_limits",
    "mini_app_audit_logs"
] as $table) {
    $count = $pdo->query(
        "SELECT COUNT(*) FROM " . $table
    )->fetchColumn();

    echo $table . ": " . $count . PHP_EOL;
}
'
```

آخرین Auditها:

```bash
php -r '
$pdo = new PDO("sqlite:" . getcwd() . "/storage/bot.sqlite");
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

print_r($pdo->query("
    SELECT
        id,
        user_id,
        session_id,
        action,
        success,
        error_code,
        occurred_at
    FROM mini_app_audit_logs
    ORDER BY id DESC
    LIMIT 50
")->fetchAll());
'
```

## فایل‌های جدید

```text
app/Modules/MiniApp/MiniAppException.php
app/Modules/MiniApp/InitDataValidator.php
app/Modules/MiniApp/MiniAppSessionRepository.php
app/Modules/MiniApp/MiniAppRateLimiter.php
app/Modules/MiniApp/MiniAppAuditLogger.php
app/Modules/MiniApp/MiniAppRepository.php
app/Modules/MiniApp/MiniAppApiController.php
app/Modules/MiniApp/MiniAppModule.php
app/Modules/MiniApp/MiniAppMaintenanceWorker.php

public/app/index.php
public/app/api.php
public/app/assets/app.css
public/app/assets/app.js

database/migrations/014_release_six_mini_app.sql
scripts/set_menu_button.php
scripts/test_release_six.php
PHASE_RELEASE_SIX_README.md
```

## فایل‌های کامل جایگزین

```text
app/Modules/Core/CoreModule.php
app/Modules/Profile/ProfileModule.php
app/Web/AdminPanelService.php
app/Web/AdminSettingRegistry.php

config/app.php
public/webhook.php
public/admin/index.php
scripts/process_jobs.php
composer.json
```
