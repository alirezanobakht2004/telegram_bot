# انتشار چهار: ابزارهای فایل و تصویر

این بسته روی انتشار سه نصب می‌شود و پردازش فایل را به Worker عمومی منتقل
می‌کند. Webhook فقط درخواست را اعتبارسنجی و در صف ثبت می‌کند؛ دانلود، تبدیل،
آپلود خروجی و پاک‌سازی فایل موقت داخل `scripts/process_jobs.php` انجام می‌شود.

## قابلیت‌ها

### QR Code

```text
/qr https://example.com
/qr 800 https://example.com
```

عدد اول اختیاری و اندازه PNG بین 250 تا 1200 پیکسل است.

نیازمندی:

- `endroid/qr-code`
- `ext-gd`

### اطلاعات و Hash فایل

روی فایل Reply کن یا دستور را Caption همان فایل قرار بده:

```text
/fileinfo
/filehash
```

`/fileinfo` خروجی JSON می‌سازد و شامل موارد قابل تشخیص است:

- نام و پسوند
- MIME
- اندازه
- SHA-256
- ابعاد و مشخصات تصویر
- تعداد صفحات PDF، در صورت وجود `pdfinfo`
- تعداد Entry، اندازه Uncompressed و Entryهای رمزگذاری‌شده ZIP، در صورت وجود `ext-zip`

`/filehash` فایل TXT شامل SHA-256 و MD5 می‌سازد.

### حذف Metadata و پردازش تصویر

```text
/removeexif
/resize 800
/resize 800x600
/compress 75
/towebp
/tojpeg
```

رفتار Resize:

- نسبت تصویر حفظ می‌شود.
- عدد تکی یک Bounding Box مربعی می‌سازد.
- `800x600` تصویر را داخل همان محدوده جا می‌دهد.
- تصویر کوچک‌تر بزرگ‌نمایی نمی‌شود.

پردازش با Imagick انجام می‌شود؛ اگر Imagick موجود نباشد، GD به‌عنوان
Fallback استفاده می‌شود.

`removeexif` تصویر را Decode و دوباره Encode می‌کند و Metadata خروجی حذف
می‌شود. در تصاویر JPEG، Orientation در صورت امکان پیش از حذف EXIF اعمال
می‌شود.

### استخراج متن PDF

```text
/pdftext
```

نیازمندی:

- `proc_open`
- `pdftotext`
- `pdfinfo`

هر دو Binary لازم‌اند؛ چون بدون `pdfinfo` سقف قطعی 20 صفحه قابل اعمال نیست.

PDF تصویری، اسکن‌شده یا رمزگذاری‌شده ممکن است متن قابل استخراج نداشته باشد.
این نسخه OCR اجرا نمی‌کند.

### تبدیل متن به فایل

```text
/totxt متن
/tojson متن
/tocsv متن
```

همچنین می‌توان روی یک پیام متنی Reply کرد.

`/tojson`:

- JSON معتبر را Pretty Print می‌کند.
- متن عادی را داخل کلید `text` قرار می‌دهد.

`/tocsv`:

- آرایه JSON از Objectها یا Arrayها را به CSV تبدیل می‌کند.
- خطوط جداشده با کاما، Tab، Semicolon یا Pipe را تشخیص می‌دهد.
- خروجی دارای UTF-8 BOM است.

### مدیریت Jobها

```text
/filejobs
/filecancel 12
/filecapabilities
```

فقط Job با وضعیت `queued` و متعلق به همان کاربر قابل لغو است. Job در حال
پردازش به‌صورت اتمی Claim شده و برای جلوگیری از خراب‌شدن فایل نیمه‌کاره لغو
نمی‌شود.

## محدودیت‌های قطعی

این سقف‌ها در چند لایه، مستقل از تنظیمات Runtime، اعمال می‌شوند:

```text
اندازه ورودی و خروجی فایل: 10 MB
تصویر: 12,000,000 پیکسل
PDF: 20 صفحه
متن ورودی/استخراجی/خروجی: 512,000 بایت
Job فعال هر کاربر: 1
Job در حال پردازش کل سرور: حداکثر 2
```

پنل Runtime می‌تواند بعضی سقف‌ها را پایین‌تر بیاورد، ولی نمی‌تواند از حدود
قطعی بالاتر ببرد.

Timeout پیش‌فرض هر Job:

```text
45 ثانیه
```

Timeout، تعداد Retry، تشخیص Job متوقف‌شده و Retention از پنل Runtime قابل
تنظیم‌اند.

## معماری صف

جریان اجرای درخواست:

```text
Telegram Update
→ Webhook validation
→ file_jobs
→ job_queue: file_tools.process
→ Scheduled Worker
→ getFile
→ دانلود محدودشده
→ پردازش در Workspace خصوصی
→ آپلود نتیجه به Telegram
→ حذف Workspace در finally
```

لایه‌های محدودکننده:

- Unique partial index در SQLite برای یک Job فعال هر کاربر
- Transaction نوع `BEGIN IMMEDIATE` هنگام Claim
- شمارش Jobهای `processing` برای سقف کل
- Retry با Backoff توسط JobRunner
- بازیابی Job متوقف‌شده
- محدودیت دانلود Streaming
- محدودیت حجم خروجی
- Deadline داخلی پردازش
- `set_time_limit`
- پاک‌سازی Workspace حتی هنگام Exception
- Job روزانه پاک‌سازی History
- Temporary cleanup عمومی موجود پروژه

Jobهای جدید:

```text
file_tools.process
file_tools.cleanup
```

Scheduled Task جدید لازم نیست. همان Task عمومی انتشار صفر استفاده می‌شود:

```bash
php /home/alirezanobakht2004/telegram_bot/scripts/process_jobs.php
```

## Capability Detection

دستور نصب و بررسی:

```bash
composer files:capabilities
```

موارد بررسی‌شده:

```text
ext-gd
ext-fileinfo
ext-zip
Imagick
image_processing
qr_png
pdftotext
pdfinfo
pdf_text
proc_open
```

نتیجه در جدول زیر Snapshot می‌شود:

```text
file_capability_snapshots
```

نبودن یک قابلیت، کل ماژول را از کار نمی‌اندازد:

| قابلیت | وابستگی |
|---|---|
| `/filehash` | PHP Hash |
| `/totxt`, `/tojson`, `/tocsv` | PHP Core |
| `/fileinfo` پایه | PHP Core |
| MIME دقیق‌تر | `ext-fileinfo` |
| اطلاعات ZIP/Office | `ext-zip` |
| پردازش تصویر | Imagick یا `ext-gd` |
| QR PNG | `endroid/qr-code` و `ext-gd` |
| PDF Text | `proc_open`, `pdftotext`, `pdfinfo` |

مسیر Binaryهای PDF در صورت نبودن داخل PATH فقط در `config/local.php` تنظیم
شود:

```php
'modules' => [
    'file_tools' => [
        'binaries' => [
            'pdftotext' => '/usr/bin/pdftotext',
            'pdfinfo' => '/usr/bin/pdfinfo',
        ],
    ],
],
```

## Migration

```text
database/migrations/012_release_four_file_tools.sql
```

جدول‌ها:

```text
file_jobs
file_capability_snapshots
```

Feature Flag:

```text
file_tools = enabled
rollout_percentage = 100
```

## Composer Dependency

بسته `composer.json` را با Dependency زیر جایگزین می‌کند:

```json
"endroid/qr-code": "^6.1.3"
```

پس از Copy فایل‌ها، Dependencyها باید روی سیستم محلی Resolve شوند و
`composer.lock` تولیدشده همراه Commit وارد Git شود.

در Windows CMD:

```bat
cd /d G:\Projects\telegram_bot

if exist composer.lock (
    composer update endroid/qr-code --with-all-dependencies
) else (
    composer update
)
```

## نصب محلی

```bat
cd /d G:\Projects\telegram_bot

composer dump-autoload -o
composer migrate
composer release-four:test
composer files:capabilities
composer check
composer jobs:process
git status --short
```

خروجی Migration:

```text
[APPLIED] 012_release_four_file_tools.sql
Migration completed. New migrations: 1
```

خروجی تست مورد انتظار:

```json
{
    "status": "passed",
    "tests": {
        "file_reference_extractor": true,
        "text_to_json": true,
        "text_to_csv": true,
        "capability_detection": true,
        "fixed_text_limit": true,
        "fixed_user_job_limit": true,
        "fixed_global_job_limit": true,
        "database": "passed"
    }
}
```

Commit:

```bat
git add .
git commit -m "feat: add queued file image and PDF tools"
git push
```

بررسی کن که `composer.lock` نیز در Commit باشد.

## استقرار Alwaysdata

```bash
cd "$HOME/telegram_bot"

git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
composer migrate
composer release-four:test
composer files:capabilities
composer check
composer jobs:process
```

در این انتشار Update Type جدیدی اضافه نشده است؛ ثبت مجدد Webhook ضروری نیست.

Task موجود باید همچنان هر یک دقیقه اجرا شود:

```bash
php /home/alirezanobakht2004/telegram_bot/scripts/process_jobs.php
```

## تست تلگرام

QR:

```text
/qr https://example.com
/qr 900 Hello World
```

اطلاعات فایل:

```text
روی یک فایل Reply:
/fileinfo
/filehash
```

تصویر:

```text
روی تصویر Reply:
/removeexif
/resize 800
/compress 70
/towebp
/tojpeg
```

PDF:

```text
روی PDF Reply:
/pdftext
```

متن:

```text
/totxt سلام دنیا
/tojson سلام دنیا
/tocsv name,age
Ali,30
Sara,28
```

وضعیت:

```text
/filejobs
/filecapabilities
```

## بررسی دیتابیس

```bash
php -r '
$pdo = new PDO("sqlite:" . getcwd() . "/storage/bot.sqlite");
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

print_r($pdo->query("
    SELECT
        id,
        user_id,
        operation,
        status,
        progress,
        attempts,
        file_name,
        output_name,
        output_size,
        error_code,
        error_message,
        created_at,
        completed_at
    FROM file_jobs
    ORDER BY id DESC
    LIMIT 30
")->fetchAll());
'
```

Capability Snapshot:

```bash
php -r '
$pdo = new PDO("sqlite:" . getcwd() . "/storage/bot.sqlite");
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

print_r($pdo->query("
    SELECT
        capability,
        available,
        version,
        details,
        checked_at
    FROM file_capability_snapshots
    WHERE id IN (
        SELECT MAX(id)
        FROM file_capability_snapshots
        GROUP BY capability
    )
    ORDER BY capability
")->fetchAll());
'
```

## Log

```text
storage/logs/file_tools.log
storage/logs/jobs.log
storage/logs/webhook.log
```

## فایل‌های جدید

```text
app/Modules/FileTools/FileToolException.php
app/Modules/FileTools/FileCapabilities.php
app/Modules/FileTools/ProcessRunner.php
app/Modules/FileTools/FileJobRepository.php
app/Modules/FileTools/FileReferenceExtractor.php
app/Modules/FileTools/FileInfoInspector.php
app/Modules/FileTools/PdfTextExtractor.php
app/Modules/FileTools/ImageProcessor.php
app/Modules/FileTools/QrCodeProcessor.php
app/Modules/FileTools/TextFileProcessor.php
app/Modules/FileTools/FileToolsModule.php
app/Modules/FileTools/FileJobWorker.php

database/migrations/012_release_four_file_tools.sql
scripts/check_file_capabilities.php
scripts/test_release_four.php
PHASE_RELEASE_FOUR_README.md
```

## فایل‌های کامل جایگزین

```text
app/Core/TelegramClient.php
app/Core/UpdateProcessor.php
app/Modules/Core/CoreModule.php
app/Modules/Profile/ProfileModule.php
app/Web/AdminSettingRegistry.php

config/app.php
public/webhook.php
scripts/process_jobs.php
composer.json
```
