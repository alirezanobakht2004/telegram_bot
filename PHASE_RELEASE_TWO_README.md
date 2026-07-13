# Release Two: Smart Alerts, Subscriptions, and Monitoring

این بسته روی انتشار یک اصلاح‌شده نصب می‌شود و شامل نسخه کامل تمام فایل‌های
جدید یا تغییریافته است.

## هزینه و سرویس‌ها

- بدون API Key جدید
- بدون سرویس پولی
- استفاده مجدد از Open-Meteo، Frankfurter، countries.dev و Telegram Bot API
- استفاده از Worker عمومی و Scheduled Task موجود `process_jobs.php`

## هشدارهای هوشمند

```text
/alert weather Tehran rain
/alert weather Tehran snow
/alert weather Tehran contains rain
/alert weather Tehran starts rain
/alert weather Tehran stops rain
/alert weather Tehran changes

/alert temperature Tehran below 0
/alert temperature Tehran above 40
/alert temperature Tehran equals 25
/alert temperature Tehran changes

/alert wind Tehran above 60
/alert currency USD EUR above 0.90
/alert currency USD EUR below 0.80
/alert currency USD EUR equals 0.85
/alert currency USD EUR changes

/alerts
/alertpause 12
/alertresume 12
/alertcancel 12
```

موتور شرط:

```text
above
below
equals
changes
contains
starts
stops
```

جلوگیری از اعلان تکراری:

- Cooldown
- Hysteresis برای شروط عددی
- Last observed value
- Last condition state
- Last triggered value
- Deduplication key
- Maximum notifications per day
- Retention برای سابقه اعلان‌ها
- Retry هنگام خطای موقت Worker یا Telegram

## اشتراک‌ها

```text
/subscribe weather Tehran daily 08:00
/subscribe weather Tehran weekly saturday 08:00
/subscribe weather Tehran monthly 1 09:00
/subscribe country Iran
/subscribe country Japan monthly 15 09:30

/subscriptions
/subscriptionpause 18
/subscriptionresume 18
/subscriptioncancel 18
```

قواعد زمان‌بندی:

- Daily
- Weekly با روزهای انگلیسی یا فارسی
- Monthly با روز 1 تا 31
- در ماه‌های کوتاه، روز به آخرین روز همان ماه محدود می‌شود
- منطقه زمانی از تنظیمات شخصی کاربر خوانده می‌شود

## مانیتورینگ سایت

```text
/status https://example.com
/monitor https://example.com 5m
/monitors
/monitorpause 12
/monitorresume 12
/monitorcancel 12

/ssl example.com
/dns example.com
/headers https://example.com
/uptime 12

/monitorreport 12 on 09:00
/monitorreport 12 off
```

فاصله مانیتور با `s`، `m`، `h` یا `d` مشخص می‌شود. مقدار پیش‌فرض حداقل
فاصله از پنل مدیریت قابل تغییر است و در این بسته 5 دقیقه است.

قابلیت‌ها:

- HTTP Status
- Response time
- TTFB
- DNS time
- Connect time
- TLS time
- Redirect chain
- Content-Type
- Final URL
- Primary IP
- SSL issuer، subject، SAN و تاریخ انقضا
- DNS A، AAAA، CNAME، MX، NS، TXT و SOA
- Headerهای امنیتی
- اعلان Down و Recovery
- Failure و Recovery threshold
- Uptime 24 ساعت، 7 روز و 30 روز
- گزارش روزانه Availability
- نمودار و سابقه Check در پنل وب

## محافظت SSRF

- فقط `http://` و `https://`
- فقط پورت‌های Allowlistشده، پیش‌فرض 80 و 443
- مسدودکردن localhost
- مسدودکردن IPv4 و IPv6 خصوصی
- مسدودکردن Loopback
- مسدودکردن Link-local
- مسدودکردن Multicast و Reserved
- مسدودکردن Metadata IPها
- DNS resolution قبل از اتصال
- DNS pinning با `CURLOPT_RESOLVE`
- بررسی IP واقعی اتصال پس از پاسخ
- بررسی مجدد مقصد هر Redirect
- محدودیت Redirect
- Timeout سخت
- محدودیت حجم پاسخ
- عدم ارسال Cookie
- عدم ارسال Authorization
- عدم استفاده از Proxy محیطی
- عدم پشتیبانی از `file://`، `ftp://` و سایر Schemeها

## Feature Flagها

Migration این Feature Flagها را با Rollout صددرصد فعال می‌کند:

```text
smart_alerts
scheduled_subscriptions
site_monitoring
```

هرکدام از بخش Feature Flags پنل مدیریت به‌صورت مستقل قابل خاموش‌کردن هستند.

## پنل مدیریت

صفحه جدید:

```text
/admin/?section=automation
```

امکانات:

- آمار هشدارهای فعال
- آمار اشتراک‌ها
- آمار مانیتورهای فعال و Down
- مشاهده تمام هشدارها
- مشاهده تمام اشتراک‌ها
- مشاهده تمام مانیتورها
- فعال، متوقف یا لغوکردن رکوردها
- نمودار Uptime روزانه
- آخرین Checkهای مانیتور
- مشاهده Status، Response time و خطای آخر
- تنظیم کامل Runtime برای هشدارها و مانیتورینگ

## Worker عمومی

Task جدیدی لازم نیست. Scheduled Task انتشار صفر باید فعال بماند:

```bash
php /home/alirezanobakht2004/telegram_bot/scripts/process_jobs.php
```

Jobهای جدید:

```text
alerts.scan
subscriptions.scan
monitoring.scan
monitoring.daily_reports
```

Job قبلی GitHub نیز در همان Worker باقی می‌ماند:

```text
github.release_watch.scan
```

## Migration

```text
010_release_two_alerts_subscriptions_monitoring.sql
```

جدول‌های جدید:

```text
smart_alerts
alert_notifications
smart_subscriptions
site_monitors
monitor_checks
monitor_notifications
```

## تست

```bash
composer dump-autoload -o
composer migrate
composer release-two:test
composer check
composer jobs:process
```

خروجی مورد انتظار:

```json
{
    "status": "passed",
    "tests": {
        "condition_engine": true,
        "hysteresis": true,
        "schedule_engine": true,
        "ssrf_guard": true,
        "unsafe_scheme_blocking": true,
        "local_dns_blocking": true,
        "database": "passed"
    }
}
```

## Runtime Settings مهم

```text
modules.alerts.enabled
modules.alerts.max_alerts_per_user
modules.alerts.max_subscriptions_per_user
modules.alerts.check_interval_seconds
modules.alerts.scan_batch_size
modules.alerts.subscription_batch_size
modules.alerts.default_cooldown_seconds
modules.alerts.default_hysteresis
modules.alerts.max_notifications_per_day
modules.alerts.notification_retention_days
modules.alerts.weather_cache_ttl
modules.alerts.currency_cache_ttl
modules.alerts.country_cache_ttl
modules.alerts.rate_limit.max_attempts
modules.alerts.rate_limit.window_seconds

modules.monitoring.enabled
modules.monitoring.max_monitors_per_user
modules.monitoring.minimum_interval_seconds
modules.monitoring.maximum_interval_seconds
modules.monitoring.scan_batch_size
modules.monitoring.report_batch_size
modules.monitoring.failure_threshold
modules.monitoring.recovery_threshold
modules.monitoring.retention_days
modules.monitoring.http.connect_timeout
modules.monitoring.http.timeout
modules.monitoring.http.max_response_bytes
modules.monitoring.http.max_redirects
modules.monitoring.rate_limit.max_attempts
modules.monitoring.rate_limit.window_seconds
```

## Logها

```text
storage/logs/alerts.log
storage/logs/subscriptions.log
storage/logs/monitoring.log
storage/logs/jobs.log
storage/logs/webhook.log
```

## فایل‌های جدید

```text
app/Modules/Alerts/ScheduleCalculator.php
app/Modules/Alerts/ConditionEvaluator.php
app/Modules/Alerts/AlertRepository.php
app/Modules/Alerts/SubscriptionRepository.php
app/Modules/Alerts/AlertDataProvider.php
app/Modules/Alerts/AlertModule.php
app/Modules/Alerts/AlertWorker.php
app/Modules/Alerts/SubscriptionWorker.php

app/Modules/Monitoring/MonitorProbe.php
app/Modules/Monitoring/SslInspector.php
app/Modules/Monitoring/DnsInspector.php
app/Modules/Monitoring/MonitorRepository.php
app/Modules/Monitoring/MonitorModule.php
app/Modules/Monitoring/MonitorWorker.php

database/migrations/010_release_two_alerts_subscriptions_monitoring.sql
scripts/test_release_two.php
PHASE_RELEASE_TWO_README.md
```

## فایل‌های کامل جایگزین

```text
app/Modules/Core/CoreModule.php
app/Modules/Profile/ProfileModule.php
app/Web/AdminSettingRegistry.php
app/Web/AdminPanelService.php
config/app.php
public/webhook.php
public/admin/index.php
scripts/process_jobs.php
composer.json
```

## Rollback عملیاتی

برای توقف فوری بدون حذف داده‌ها، Feature Flagهای زیر یا Moduleهای متناظر را از
پنل خاموش کنید:

```text
smart_alerts
scheduled_subscriptions
site_monitoring
modules.alerts.enabled
modules.monitoring.enabled
```

Migration برگشتی مخرب ارائه نشده است تا تاریخچه Uptime و هشدارها ناخواسته حذف
نشود.
