# Reminders Phase

این فاز یادآورهای یک‌باره را با SQLite و Scheduled Task اضافه می‌کند.

## هزینه

- بدون API Key جدید
- بدون سرویس پولی
- فقط PHP، SQLite و Telegram Bot API
- Scheduled Task رایگان در پنل alwaysdata

## دستورها

- `/remind 10m خرید شیر`
- `/remind 2 ساعت تماس`
- `/remind فردا 09:00 جلسه`
- `/remind امروز 18:30 ورزش`
- `/remind 2026-07-15 18:30 پرداخت قبض`
- `/reminders`
- `/reminderhistory`
- `/remindercancel 12`
- `/reminderdelete 12`

تاریخ‌های عددی میلادی هستند.

## Worker

```bash
php scripts/process_reminders.php
```

در alwaysdata یک Scheduled Task با فاصله یک دقیقه ساخته شود:

```text
php /home/alirezanobakht2004/telegram_bot/scripts/process_reminders.php
```

## پنل وب

صفحه `یادآورها` به پنل وب اضافه می‌شود:

- مشاهده صف و تاریخچه
- اجرای دستی Worker
- لغو یادآور
- Retry یادآور ناموفق
- آمار Worker
- تنظیم Batch، Retry، Retention، Rate Limit و محدودیت‌ها

## فایل‌های جدید

- `app/Modules/Reminders/ReminderTimeParser.php`
- `app/Modules/Reminders/ReminderRepository.php`
- `app/Modules/Reminders/ReminderWorker.php`
- `app/Modules/Reminders/ReminderModule.php`
- `database/migrations/007_reminders.sql`
- `scripts/process_reminders.php`
- `scripts/test_reminders.php`

## فایل‌های کامل جایگزین

- `app/Modules/Core/CoreModule.php`
- `app/Web/AdminSettingRegistry.php`
- `app/Web/AdminPanelService.php`
- `config/app.php`
- `public/webhook.php`
- `public/admin/index.php`
- `composer.json`
