# V2 Foundation and Analytics

این انتشار زیرساخت مشترک قابلیت‌های نسخه دوم ربات را اضافه می‌کند. هدف آن ثبت داده‌های قابل اتکا، پشتیبانی از انواع Update جدید، صف عمومی Job، کنترل Feature Flagها و تحلیل عملیاتی از روز اول است.

## اجزای اصلی

- `UsageTracker` و `UsageSpan`
- `ApiMetricsTracker`
- `CacheMetricsTracker`
- `CommandHistory`
- `UpdateContext`
- `TelemetryContext`
- `EventDispatcher`
- `CallbackRouter`
- `InlineQueryRouter`
- `FeatureRegistry`
- `SafeHttpClient`
- `SsrfGuard`
- `TemporaryFileManager`
- `JobQueue`
- `JobRunner`
- `JobLock`
- `DeadLetterQueue`
- `AnalyticsMaintenance`
- `AnalyticsCsvExporter`

## Migration

```text
008_v2_foundation_analytics.sql
```

جدول‌ها:

```text
usage_events
command_history
api_metrics
cache_metrics
feature_flags
job_queue
job_runs
dead_letter_jobs
job_locks
```

جدول `job_locks` برای جلوگیری از اجرای هم‌زمان Worker اضافه شده است.

## Updateهای Webhook

```text
message
edited_message
callback_query
inline_query
chosen_inline_result
my_chat_member
chat_member
chat_join_request
poll
poll_answer
```

در این انتشار زیرساخت Inline وجود دارد، اما Feature Flag آن به‌صورت پیش‌فرض خاموش می‌ماند تا Handlerهای واقعی در انتشار یک ثبت شوند.

## Analytics پنل وب

مسیرها:

```text
/admin/?section=analytics
/admin/?section=features
```

قابلیت‌ها:

- آمار روزانه و ساعتی
- دستورها و دکمه‌های پراستفاده
- آمار ماژول‌ها
- نرخ موفقیت و زمان پاسخ
- خطاها بر اساس ماژول و عملیات
- API latency، وضعیت HTTP و حجم پاسخ
- Cache hit rate
- Retention روزهای ۱، ۷ و ۳۰
- وضعیت Job Queue و Dead Letter
- لغو Job در صف
- Replay کردن Dead Letter
- Export CSV
- پاک‌سازی دستی داده‌های قدیمی
- Feature Flag با Rollout درصدی پایدار

## حفظ حریم خصوصی

ثبت آرگومان دستورها به‌صورت پیش‌فرض خاموش است:

```text
analytics.command_history.store_arguments = false
```

Token تلگرام، Webhook Secret، Password Hash، Authorization Header و Cookie در Analytics ذخیره نمی‌شوند.

## Safe HTTP و SSRF

- فقط Scheme و Portهای Whitelist‌شده
- رد کردن Credential داخل URL
- مسدودکردن Loopback، Private، Link-local، CGNAT و شبکه‌های رزروشده
- بررسی تمام IPهای DNS
- Pin کردن IP با `CURLOPT_RESOLVE`
- اعتبارسنجی مجدد هر Redirect
- محدودیت Redirect، Timeout و حجم پاسخ
- ممنوعیت ارسال Headerهای حساس

## Generic Job Worker

اجرای دستی:

```bash
php scripts/process_jobs.php
```

یا:

```bash
composer jobs:process
```

Scheduled Task پیشنهادی در Alwaysdata:

```text
php /home/alirezanobakht2004/telegram_bot/scripts/process_jobs.php
```

فاصله اجرا: هر یک دقیقه.

Jobهای نگهداری داخلی:

- `analytics.cleanup`
- `temporary.cleanup`

صف دارای Priority، Retry تصاعدی، Lock عمومی، بازیابی Jobهای رهاشده و Dead Letter است.

## Feature Flagهای پیش‌فرض

```text
analytics = enabled
generic_jobs = enabled
callback_routing = enabled
inline_routing = disabled
```

## تست

```bash
composer v2:test
```

در محیط دارای `pdo_sqlite`، این موارد نیز تست می‌شوند:

- ایجاد تمام جدول‌ها
- Usage Tracker
- API و Cache Metrics
- حریم خصوصی Command History
- Feature Registry
- Job Runner
- Dead Letter
- Replay و جلوگیری از Replay تکراری
- Analytics Cleanup

## فایل‌های جدید

```text
app/Core/AnalyticsMaintenance.php
app/Core/ApiMetricsTracker.php
app/Core/CacheMetricsTracker.php
app/Core/CallbackQueryContext.php
app/Core/CallbackRouter.php
app/Core/CommandHistory.php
app/Core/DeadLetterQueue.php
app/Core/EventDispatcher.php
app/Core/FeatureRegistry.php
app/Core/InlineQueryContext.php
app/Core/InlineQueryRouter.php
app/Core/JobLock.php
app/Core/JobQueue.php
app/Core/JobRunner.php
app/Core/SafeHttpClient.php
app/Core/SsrfGuard.php
app/Core/TelemetryContext.php
app/Core/TemporaryFileManager.php
app/Core/UpdateContext.php
app/Core/UsageSpan.php
app/Core/UsageTracker.php
app/Web/AnalyticsCsvExporter.php
database/migrations/008_v2_foundation_analytics.sql
public/admin/assets/admin.js
scripts/process_jobs.php
scripts/test_v2_foundation.php
```

## فایل‌های کامل جایگزین

```text
app/Core/CommandRouter.php
app/Core/FileCache.php
app/Core/HttpClient.php
app/Core/MessageContext.php
app/Core/TelegramClient.php
app/Core/UpdateProcessor.php
app/Web/AdminPanelService.php
app/Web/AdminSettingRegistry.php
config/app.php
public/admin/index.php
public/admin/assets/admin.css
public/webhook.php
scripts/set_webhook.php
composer.json
```

## استقرار

ترتیب لازم:

```bash
composer install --no-dev --optimize-autoloader
composer migrate
composer v2:test
composer check
composer telegram:set-webhook
composer telegram:webhook-info
composer jobs:process
```

Migration باید قبل از بازکردن پنل وب جدید اجرا شود. به‌دلیل تغییر `allowed_updates`، Webhook نیز باید دوباره ثبت شود.
