# Calculator and Unit Conversion Phase

این فاز کاملاً داخل PHP اجرا می‌شود و هیچ API خارجی یا هزینه‌ای ندارد.

## قابلیت‌ها

- `/calc 2*(3+4)`
- `/calculate sqrt(81)`
- `/convert 10 km mi`
- `/unit 32 F C`
- `/units`
- دکمه `🧮 ماشین حساب`
- ورودی مرحله‌ای در چت خصوصی
- اعداد فارسی
- Parser امن بدون `eval`
- Rate limit مستقل

## محاسبات

عملگرها:

- `+`
- `-`
- `*`
- `/`
- `%`
- `^`
- پرانتز

توابع:

- `sqrt`
- `abs`
- `round`
- `floor`
- `ceil`
- `sin`
- `cos`
- `tan`
- `ln`
- `log`

ثابت‌ها:

- `pi`
- `e`

## دسته‌های تبدیل واحد

- طول
- جرم
- دما
- مساحت
- حجم
- سرعت
- زمان
- داده دیجیتال

## فایل‌های جدید

- `app/Modules/Calculator/ExpressionCalculator.php`
- `app/Modules/Calculator/UnitConverter.php`
- `app/Modules/Calculator/CalculatorModule.php`
- `scripts/test_calculator.php`

## فایل‌های کامل جایگزین

- `app/Modules/Core/CoreModule.php`
- `config/app.php`
- `public/webhook.php`

## هزینه

- بدون API Key
- بدون سرویس خارجی
- بدون Migration جدید
- فقط PHP
