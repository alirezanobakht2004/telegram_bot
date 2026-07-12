# Phase: API Infrastructure and Animals Module

این بسته شامل فایل‌های جدید و فایل‌های کامل جایگزین برای فاز تصاویر حیوانات است.

## فایل‌های جدید

- `app/Core/HttpResponse.php`
- `app/Core/HttpClient.php`
- `app/Core/FileCache.php`
- `app/Core/RateLimitResult.php`
- `app/Core/RateLimiter.php`
- `app/Modules/Animals/AnimalsModule.php`
- `database/migrations/002_rate_limits.sql`

## فایل‌های جایگزین کامل

- `app/Core/TelegramClient.php`
- `app/Core/MessageContext.php`
- `app/Modules/Core/CoreModule.php`
- `config/app.php`
- `public/webhook.php`

فایل `config/local.php` و هیچ توکن یا Secret داخل این بسته نیست.
