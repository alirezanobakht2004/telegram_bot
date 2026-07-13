# Release Five Hotfix 1

این بسته شامل فایل‌های کامل جایگزین است و سه مشکل مشاهده‌شده روی PHP 8.4 و
Windows را برطرف می‌کند:

1. افزودن Importهای جاافتاده Quiz در `scripts/process_jobs.php`
2. تعیین صریح Escape Parameter برای `fgetcsv` و `fputcsv`
3. بستن PDO و Retry پاک‌سازی فایل SQLite موقت در تست Windows

## فایل‌های کامل جایگزین

```text
app/Modules/Quiz/QuizCsvService.php
scripts/process_jobs.php
scripts/test_release_five.php
```

## نصب

محتویات ZIP را داخل ریشه پروژه Copy و Replace کن:

```text
G:\Projects\telegram_bot
```

سپس:

```bat
cd /d G:\Projects\telegram_bot

composer dump-autoload -o
composer release-five:test
composer check
composer jobs:process
git status --short
```

Migration جدیدی وجود ندارد و اجرای دوباره `composer migrate` لازم نیست.

## Commit

```bat
git add app/Modules/Quiz/QuizCsvService.php scripts/process_jobs.php scripts/test_release_five.php
git commit -m "fix: repair quiz worker imports and PHP 8.4 CSV warnings"
git push
```
