# Secure Web Admin Panel

پنل مدیریت وب در این مسیر قرار می‌گیرد:

```text
/admin/
```

## امکانات

- ورود با Password Hash
- Session امن و انقضای خودکار
- CSRF protection
- محدودیت تلاش ورود
- Security headers و CSP
- داشبورد آمار کامل
- فعالیت ۱۴ روز اخیر
- تنظیم Runtime ماژول‌ها
- تغییر Cache TTL
- تغییر Rate Limit و Window
- فعال یا غیرفعال‌کردن ماژول‌ها
- پاک‌سازی کش هر ماژول
- مدیریت و مسدودی کاربران
- مدیریت و مسدودی چت‌ها
- ارسال همگانی Batch‌بندی‌شده
- مشاهده و پاک‌کردن Logها
- Audit Log تغییرات
- سلامت PHP، SQLite، حافظه و دیسک
- پاک‌سازی رکوردهای منقضی
- VACUUM و ANALYZE
- ساخت، دانلود و حذف Backup دیتابیس

## ساخت رمز پنل

```bash
php scripts/admin_password_hash.php
```

خروجی شامل یک Password تصادفی و Hash آن است. Password را ذخیره کن و فقط Hash را به فایل محرمانه `config/local.php` اضافه کن:

```php
'web_admin' => [
    'password_hash' => 'HASH_GENERATED_HERE',
],
```

فایل `config/local.php` نباید وارد Git شود.

## Runtime Settings

Overrideها در جدول `runtime_settings` ذخیره می‌شوند و فایل `config/app.php` را تغییر نمی‌دهند. Secretهایی مثل Telegram Token، Webhook Secret و Password Hash در پنل قابل مشاهده یا ویرایش نیستند.

## کش انتخابی

از این فاز به بعد `FileCache` نام کلید را در Metadata فایل ذخیره می‌کند. بنابراین کش ماژول‌های آب‌وهوا، حیوانات، ارز و کشورها جداگانه قابل پاک‌سازی است. فایل‌های قدیمی بدون Metadata فقط با پاک‌سازی کل کش حذف می‌شوند.

## مسدودی واقعی

- `users.is_blocked` برای مسدودکردن کاربر استفاده می‌شود.
- ستون جدید `chats.admin_blocked` برای مسدودکردن چت استفاده می‌شود.
- `UpdateProcessor` پیش از اجرای Router این دو وضعیت را بررسی می‌کند.

## فایل‌های جدید

- `app/Core/RuntimeSettings.php`
- `app/Web/AdminAuth.php`
- `app/Web/AdminSettingRegistry.php`
- `app/Web/AdminPanelService.php`
- `public/admin/index.php`
- `public/admin/assets/admin.css`
- `public/admin/.htaccess`
- `database/migrations/006_web_admin_runtime.sql`
- `scripts/admin_password_hash.php`
- `scripts/test_web_admin.php`

## فایل‌های کامل جایگزین

- `app/Core/FileCache.php`
- `app/Core/UpdateProcessor.php`
- `config/app.php`
- `public/webhook.php`
