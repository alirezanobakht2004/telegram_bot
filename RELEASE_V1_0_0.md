# انتشار پایدار v1.0.0

## هدف

این بسته پروژه را از مجموعه انتشارهای مرحله‌ای به نسخه پایدار `1.0.0`
منتقل می‌کند و پیام معرفی نسخه را با زیرساخت Broadcast موجود برای همه چت‌های
خصوصی فعال ارسال می‌کند.

## تغییرات این بسته

- اضافه‌شدن فایل `VERSION`
- ثبت نسخه پایدار در `config/app.php`
- نمایش نسخه در `/start`، `/menu` و `/help`
- اضافه‌شدن دستور `/version`
- متن رسمی معرفی نسخه فارسی
- Script امن و Idempotent برای Broadcast
- تست نهایی نسخه و پایگاه داده
- مستندات کاربر و مدیر

## دستورات Composer

```bash
composer v1:test
composer v1:preview
composer v1:create-announcement
composer v1:announce
```

### Preview

هیچ پیامی ارسال نمی‌کند و فقط متن، طول و تعداد گیرندگان واجد شرایط را نشان
می‌دهد:

```bash
composer v1:preview
```

### ساخت بدون ارسال

Broadcast را در دیتابیس ایجاد می‌کند تا از پنل وب پردازش شود:

```bash
composer v1:create-announcement
```

### ارسال کامل

Broadcast را ایجاد یا Resume می‌کند و در Batchهای کوچک برای تمام کاربران
خصوصی فعال ارسال می‌کند:

```bash
composer v1:announce
```

Script بر اساس متن دقیق Campaign عمل می‌کند. اجرای دوباره پس از تکمیل باعث
ارسال تکراری نمی‌شود و وضعیت `already-completed` برمی‌گرداند.

## گیرندگان

فقط چت‌های زیر وارد Campaign می‌شوند:

```sql
WHERE type = 'private'
  AND is_active = 1
  AND admin_blocked = 0
  AND telegram_id > 0
```

کاربرانی که ربات را Block کرده‌اند هنگام خطای دائمی غیرفعال می‌شوند.

## نصب محلی

```bat
cd /d G:\Projects\telegram_bot

composer dump-autoload -o
composer migrate
composer v1:test
composer v1:preview
composer check
git status --short
```

## استقرار

```bash
cd "$HOME/telegram_bot"

git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
composer migrate
composer v1:test
composer check
composer jobs:process
composer telegram:webhook-info
composer telegram:set-menu-button
```

پس از بررسی Preview:

```bash
composer v1:announce
```

## Tag نهایی

```bash
git tag -a v1.0.0 -m "Smart Toolbox Telegram Bot v1.0.0"
git push origin v1.0.0
```
