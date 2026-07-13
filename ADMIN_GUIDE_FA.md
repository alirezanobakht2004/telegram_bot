# راهنمای مدیر v1.0.0

## بررسی پیش از انتشار

```bash
composer migrate
composer v1:test
composer check
composer jobs:process
composer telegram:webhook-info
composer files:capabilities
```

## ارسال پیام معرفی

ابتدا Preview:

```bash
composer v1:preview
```

سپس ارسال:

```bash
composer v1:announce
```

خروجی شامل این مقادیر است:

```text
broadcast_id
total
sent
failed
pending
status
```

Broadcast در جدول‌های موجود Admin ذخیره می‌شود و از مسیر زیر نیز قابل مشاهده
است:

```text
/admin/?section=broadcasts
```

## ارسال دستی از پنل

متن فایل زیر را در بخش «ارسال همگانی» قرار دهید:

```text
BROADCAST_V1_0_0_FA.txt
```

این روش جایگزین Script است و نباید هم‌زمان با `composer v1:announce` اجرا شود.

## مدیریت خطاها

```bash
composer jobs:process
tail -n 100 storage/logs/jobs.log
tail -n 100 storage/logs/webhook.log
```

در پنل Broadcast می‌توان گیرندگان ناموفق را Retry کرد.

## مدیریت گروه

ربات بر اساس قابلیت فعال به دسترسی‌های زیر نیاز دارد:

```text
Delete Messages
Restrict Members
Ban Users
Invite Users
Manage Chat
```

## Mini App

```bash
composer telegram:set-menu-button
```

آدرس نهایی:

```text
https://alirezanobakht2004.alwaysdata.net/app/
```
