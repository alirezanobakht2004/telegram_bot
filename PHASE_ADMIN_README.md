# Admin Module Phase

این فاز پنل مدیریت داخلی و رایگان را اضافه می‌کند.

## دسترسی

فقط Telegram user IDهای موجود در `config/app.php` و کلید `admins`
می‌توانند از دستورهای مدیریت استفاده کنند.

Admin فعلی:

- `47729048`

پنل مدیریت فقط در چت خصوصی ربات قابل استفاده است.

## دستورها

- `/admin`
- `/stats`
- `/users 10`
- `/chats 10`
- `/health`
- `/broadcast متن پیام`
- `/broadcastnext [ID]`
- `/broadcaststatus [ID]`
- `/broadcastcancel ID`
- `/broadcastretry ID`

## ارسال همگانی

- فقط به چت‌های خصوصی فعال
- صف در SQLite
- پردازش دستی و Batch‌بندی‌شده
- Batch پیش‌فرض: 5 گیرنده
- ثبت موفق و ناموفق برای هر گیرنده
- غیرفعال‌کردن خودکار Chat در خطاهای دائمی مانند Block
- بدون Cron و بدون سرویس پولی

## فایل‌های جدید

- `app/Modules/Admin/AdminModule.php`
- `database/migrations/005_admin_broadcasts.sql`

## فایل‌های کامل جایگزین

- `config/app.php`
- `public/webhook.php`

## هزینه

- بدون API Key جدید
- بدون سرویس خارجی جدید
- بدون Cron پولی
- فقط PHP، Telegram Bot API و SQLite
