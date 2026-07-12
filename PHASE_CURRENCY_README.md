# Currency Module Phase

ماژول تبدیل ارز با Frankfurter v2.

## هزینه

- بدون API Key
- بدون سرویس پولی
- دارای Cache یک‌ساعته
- Rate limit مستقل

## دستورات

- `/currency 100 USD EUR`
- `/currency USD GBP`
- `/currency 1 دلار یورو`
- `/rate 100 USD EUR`
- `/currencies`
- `/cancel`

## نکته IRR

نرخ IRR داده مرجع/رسمی است و نرخ بازار آزاد تومان ایران نیست.

## فایل‌های جدید

- `app/Modules/Currency/CurrencyProviderInterface.php`
- `app/Modules/Currency/FrankfurterProvider.php`
- `app/Modules/Currency/CurrencyModule.php`

## فایل‌های کامل جایگزین

- `app/Modules/Core/CoreModule.php`
- `config/app.php`
- `public/webhook.php`

این فاز Migration جدید ندارد.
