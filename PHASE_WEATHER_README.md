# Weather Module Phase

این بسته ماژول آب‌وهوا را با Open-Meteo اضافه می‌کند.

## قابلیت‌ها

- `/weather Tehran`
- `/weather تهران`
- دکمه `🌤 آب‌وهوا`
- دریافت نام شهر به‌صورت مرحله‌ای در چت خصوصی
- پیش‌بینی چهارروزه
- کش جست‌وجوی شهر و پیش‌بینی
- Rate limit مستقل
- ذخیره موقت وضعیت مکالمه در SQLite
- `/cancel` برای لغو دریافت نام شهر

## فایل‌های کامل جایگزین

- `app/Core/CommandRouter.php`
- `app/Modules/Core/CoreModule.php`
- `config/app.php`
- `public/webhook.php`

## فایل‌های جدید

- `app/Core/ConversationStateStore.php`
- `app/Modules/Weather/WeatherModule.php`
- `database/migrations/003_conversation_states.sql`
