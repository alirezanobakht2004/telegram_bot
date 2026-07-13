# v1.0.0 Hotfix 1

این Hotfix تست نهایی نسخه 1.0.0 را با رفتار واقعی FeatureRegistry هماهنگ
می‌کند.

## علت خطا

بعضی Feature Flagها مانند `analytics`، `generic_jobs` و `callback_routing`
به‌صورت پیش‌فرض در `config/app.php` تعریف شده‌اند و الزاماً رکورد مستقلی در
جدول `feature_flags` ندارند.

کد اجرایی پروژه ابتدا Overrideهای دیتابیس را می‌خواند و در نبود رکورد، از
`features.defaults` استفاده می‌کند. تست قبلی فقط وجود رکورد در دیتابیس را
بررسی می‌کرد و به همین دلیل به‌اشتباه شکست می‌خورد.

## فایل کامل جایگزین

```text
scripts/test_v1_0_0.php
```

## اجرا

```bat
composer dump-autoload -o
composer v1:test
composer v1:preview
composer check
```

Migration جدیدی وجود ندارد.
