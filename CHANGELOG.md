# Changelog

## 1.0.0 — 2026-07-13

نخستین نسخه پایدار عمومی «جعبه ابزار».

### قابلیت‌های عمومی

- آب‌وهوا، کشورها و تبدیل ارز
- ماشین حساب، تبدیل واحد و ابزارهای عمومی
- پروفایل، علاقه‌مندی، میان‌بر و تاریخچه دستورها
- Wikipedia، GitHub و Release Watch
- ابزارهای توسعه‌دهندگان بدون اجرای کد
- Inline Mode برای استفاده در همه چت‌ها

### زمان‌بندی و مانیتورینگ

- یادآورهای زمان‌دار و تقویمی
- هشدارهای شرطی آب‌وهوا، دما، باد و ارز
- اشتراک‌های روزانه، هفتگی و ماهانه
- مانیتورینگ سایت، SSL، DNS و Headerها
- Queue، Retry، Backoff و Dead Letter

### مدیریت گروه

- اخطار، Mute، Ban، Kick و Purge
- ضد Flood، ضد پیام تکراری و ضد لینک
- Whitelist دامنه و Blacklist کلمات
- Captcha، Welcome، Goodbye و قوانین
- لینک دعوت و درخواست عضویت
- Audit کامل عملیات مدیران

### فایل و تصویر

- QR، اطلاعات و Hash فایل
- حذف Metadata، Resize، Compress و تبدیل تصویر
- استخراج متن PDF
- تبدیل متن به TXT، JSON و CSV
- پردازش فایل در Worker با محدودیت منابع

### مسابقه و امتیاز

- Quiz، Trivia، Math Game و Word Game
- Daily Challenge
- Score، XP، Level و Streak
- Achievement و Leaderboard گروهی و جهانی
- بانک سؤال و مدیریت CSV

### Mini App

- داشبورد کاربران در `/app/`
- مدیریت یادآورها، هشدارها، اشتراک‌ها و مانیتورها
- مدیریت علاقه‌مندی‌ها، میان‌برها و تنظیمات شخصی
- احراز هویت با Telegram `initData`
- Session امن، CSRF، CSP، Rate Limit و Audit

### زیرساخت

- SQLite با WAL
- Webhook امن و Secret Token
- Analytics و Command History
- Feature Flags
- Job Queue عمومی و Lock
- Admin Panel وب
- Backup، Log و Runtime Settings
