# Countries Module Phase

ماژول اطلاعات کشورها با countries.dev.

## هزینه

- رایگان
- بدون API Key
- بدون ثبت‌نام
- بدون پلن پولی در پروژه
- Cache یک‌روزه برای اطلاعات کشورها
- Rate limit مستقل داخل ربات

## قابلیت‌ها

- `/country Iran`
- `/country ایران`
- `/country JP`
- `/countrycode DE`
- `/randomcountry`
- دکمه `🌍 کشورها`
- ورودی مرحله‌ای در چت خصوصی
- نمایش پرچم در صورت وجود PNG/JPG
- جمعیت، مساحت، تراکم، پایتخت، ارز، زبان، تماس، timezone و کدهای ISO
- پشتیبانی از نام فارسی کشورهای پرکاربرد

## فایل‌های جدید

- `app/Modules/Countries/CountryProviderInterface.php`
- `app/Modules/Countries/CountriesDevProvider.php`
- `app/Modules/Countries/CountriesModule.php`

## فایل‌های کامل جایگزین

- `app/Modules/Core/CoreModule.php`
- `config/app.php`
- `public/webhook.php`

این فاز Migration جدید ندارد.
