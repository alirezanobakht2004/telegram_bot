# Utilities Module Phase

این فاز کاملاً داخل PHP اجرا می‌شود و هیچ API خارجی یا هزینه‌ای ندارد.

## ابزارها

- `/tools`
- `/password 24`
- `/uuid`
- `/sha256 hello`
- `/md5 hello`
- `/base64 hello`
- `/base64decode aGVsbG8=`
- `/count متن`
- `/random 1 100`
- `/coin`
- `/timestamp`
- `/timestamp 1783886786`
- `/timestamp 2026-07-12 23:00`

## دکمه‌های خصوصی

- رمز تصادفی
- UUID
- SHA-256
- MD5
- Base64 Encode/Decode
- شمارش متن
- عدد تصادفی
- شیر یا خط
- زمان یونیکس

## هزینه

- بدون API Key
- بدون سرویس خارجی
- بدون پلن پولی
- بدون Migration جدید

## فایل جدید

- `app/Modules/Utilities/UtilitiesModule.php`

## فایل‌های کامل جایگزین

- `app/Modules/Core/CoreModule.php`
- `config/app.php`
- `public/webhook.php`
