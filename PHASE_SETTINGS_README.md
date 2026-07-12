# Settings Module Phase

این فاز تنظیمات شخصی را بدون API خارجی و بدون هزینه اضافه می‌کند.

## قابلیت‌ها

- `/settings`
- `/settimezone Asia/Tehran`
- `/setpasswordlength 24`
- `/resetsettings`
- دکمه `⚙️ تنظیمات`
- ذخیره تنظیمات در SQLite
- منطقه زمانی سفارشی برای `/timestamp`
- طول پیش‌فرض سفارشی برای `/password`
- ورودی مرحله‌ای و `/cancel`

## فایل‌های جدید

- `app/Core/UserPreferenceStore.php`
- `app/Modules/Settings/SettingsModule.php`
- `database/migrations/004_user_preferences.sql`

## فایل‌های کامل جایگزین

- `app/Modules/Utilities/UtilitiesModule.php`
- `app/Modules/Core/CoreModule.php`
- `config/app.php`
- `public/webhook.php`

## هزینه

- بدون API Key
- بدون سرویس خارجی
- بدون پلن پولی
- فقط SQLite و PHP
