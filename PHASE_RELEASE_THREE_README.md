# Release Three: Professional Group Management

این بسته روی انتشار دو نصب می‌شود و مدیریت حرفه‌ای گروه‌ها را به ربات اضافه
می‌کند. تمام فایل‌های تغییریافته به‌صورت کامل داخل بسته قرار دارند.

## قابلیت‌ها

### اخطار

- `/warn`
- `/warnings`
- `/unwarn`
- `/clearwarnings`

بهترین روش تعیین کاربر، Reply روی پیام او است:

```text
Reply → /warn ارسال لینک تکراری
Reply → /warnings
Reply → /unwarn
Reply → /clearwarnings
```

شناسه عددی و `@username` شناخته‌شده در دیتابیس نیز پشتیبانی می‌شوند.

سقف اخطار، Action خودکار و مدت Action برای هر گروه مستقل است:

- بدون Action
- Mute
- Ban

### محدودیت اعضا

- `/mute 10m`
- `/mute @username 2h دلیل`
- `/unmute`
- `/ban`
- `/ban 7d دلیل`
- `/unban`
- `/kick`

`mute` فقط در Supergroup اجرا می‌شود. پیش از هر عملیات، وضعیت مدیر اجراکننده،
مجوز ربات و وضعیت کاربر هدف با `getChatMember` بررسی می‌شود. مالک گروه و تمام
Administratorها هدف Mute، Ban، Kick یا Warn Action خودکار قرار نمی‌گیرند.

دستورهای مدیر ناشناس پشتیبانی نمی‌شوند؛ چون Telegram در این حالت شناسه واقعی
مدیر را در پیام معمولی ارائه نمی‌کند.

### پاک‌سازی پیام

- `/purge 20`
- Reply روی یک پیام → `/purge`

حداکثر تعداد قابل تنظیم است و سقف Telegram برای `deleteMessages` رعایت می‌شود.

### Slow Mode

- `/slowmode 10`
- `/slowmode 30s`
- `/slowmode off`
- `/slowmode status`

Bot API متد مستقیمی برای تغییر Slow Mode بومی گروه ارائه نمی‌کند. این قابلیت
به‌صورت Bot-enforced اجرا می‌شود: ربات فاصله پیام‌های هر عضو را ثبت و پیام‌های
زودتر از زمان مجاز را حذف می‌کند.

### قوانین، Welcome و Goodbye

- `/rules`
- `/setrules متن`
- Reply روی متن → `/setrules`

- `/setwelcome متن`
- `/welcome on|off|status`

- `/setgoodbye متن`
- `/goodbye on|off|status`

متغیرهای قالب:

- `{first_name}`
- `{last_name}`
- `{full_name}`
- `{username}`
- `{user_id}`
- `{chat_title}`

### ضداسپم

- `/antispam on`
- `/antispam off`
- `/antispam status`

شامل:

- Flood window
- تعداد پیام مجاز
- تشخیص پیام تکراری
- پنجره زمانی تکرار
- کش نقش مدیران برای کاهش فراخوانی Telegram
- Fail-open هنگام خطای موقت Telegram برای جلوگیری از حذف اشتباه پیام مدیر
- Cooldown واقعی برای اعلان AutoMod

### ضد لینک

- `/antilink on|off|status`
- `/linkwhitelist add example.com`
- `/linkwhitelist remove example.com`
- `/linkwhitelist list`

همه لینک‌ها هنگام فعال‌بودن Anti-link ممنوع‌اند، مگر Domain اصلی یا Subdomain
آن در Whitelist باشد.

### کلمات ممنوع

- `/badwords on|off|status`
- `/badwords add عبارت ممنوع`
- `/badwords remove عبارت ممنوع`
- `/badwords list`
- `/badwords clear`

متن فارسی قبل از بررسی Normalize می‌شود.

### کپچای عضو جدید

- `/captcha on|off|status`

عضو جدید در Supergroup موقتاً محدود می‌شود و یک سؤال جمع چهارگزینه‌ای دریافت
می‌کند. تنظیمات مستقل هر گروه:

- مهلت پاسخ
- حداکثر تلاش
- Kick یا Ban پس از شکست
- لغو دستی از پنل وب
- پردازش Expiry توسط Worker

### لینک دعوت

- `/invitelink`
- `/invitelink 1d`
- `/invitelink 1d 25 Campaign`
- `/invitelink 7d request Private`
- `/invitelinks`
- `/revokelink 12`

پارامتر `request` لینک را به Join Request تبدیل می‌کند. در این حالت Member
Limit ارسال نمی‌شود.

### درخواست عضویت

- `/joinrequests`
- `/joinrequests approve 12`
- `/joinrequests decline 12`
- `/joinrequests approveall`
- `/joinrequests declineall`
- `/joinrequests mode manual`
- `/joinrequests mode approve`
- `/joinrequests mode decline`

در حالت دستی، ربات برای مدیران دکمه Inline تأیید و رد می‌سازد.

### Audit

همه موارد زیر در `group_audit_logs` ثبت می‌شوند:

- عملیات مدیران
- اخطار
- محدودیت
- AutoMod
- Join و Leave
- کپچا
- لینک دعوت
- درخواست عضویت
- تغییر تنظیمات از پنل وب
- موفق یا ناموفق بودن عملیات

## پنل مدیریت وب

مسیر:

```text
/admin/?section=group_management
```

قابلیت‌ها:

- انتخاب گروه
- مشاهده آمار اخطار، محدودیت، کپچا و Join Request
- تغییر کامل تنظیمات مستقل گروه
- مدیریت Domain Whitelist
- مدیریت کلمات ممنوع
- تأیید و رد درخواست عضویت
- پاک‌کردن اخطارهای یک کاربر
- مشاهده و رفع Mute/Ban
- لغو کپچا و برداشتن محدودیت
- ساخت لینک دعوت
- لغو لینک دعوت
- مشاهده Audit گروه
- اجرای دستی Worker

## Worker عمومی

Scheduled Task جدید لازم نیست. Task موجود انتشار صفر باید فعال بماند:

```bash
php /home/alirezanobakht2004/telegram_bot/scripts/process_jobs.php
```

Job جدید:

```text
group_management.scan
```

وظایف Job:

- پایان Mute و Ban زمان‌دار
- پردازش کپچای منقضی
- پاک‌سازی Audit و داده‌های قدیمی
- پاک‌سازی Activity و Role Cache قدیمی

## Migration

```text
011_release_three_group_management.sql
```

جدول‌ها:

- `group_settings`
- `group_warnings`
- `group_sanctions`
- `group_domain_whitelist`
- `group_bad_words`
- `group_member_roles`
- `group_member_activity`
- `group_captcha_challenges`
- `group_invite_links`
- `group_join_requests`
- `group_audit_logs`

Feature Flag:

```text
group_management = enabled
rollout_percentage = 100
```

## Telegram Updateها

این انتشار از Updateهای زیر استفاده می‌کند:

- `message`
- `edited_message`
- `callback_query`
- `chat_member`
- `chat_join_request`
- `my_chat_member`

این Updateها از انتشار صفر در `allowed_updates` موجود هستند. پس از Deploy،
`telegram:webhook-info` بررسی شود. در صورت نبودن هرکدام، Webhook دوباره ثبت شود.

## حقوق لازم برای ربات

ربات باید Administrator باشد. بر حسب قابلیت فعال، این حقوق لازم است:

- Delete messages
- Restrict members
- Ban users
- Invite users
- Manage chat
- Pin messages برای توسعه‌های مبتنی بر Pin

قابلیت‌های فعلی قبل از عملیات، حق دقیق موردنیاز را بررسی می‌کنند.

## Privacy Mode

برای مدیریت کامل پیام‌ها، ربات باید Administrator گروه باشد. ربات Admin حتی
با Privacy Mode روشن پیام‌های گروه را دریافت می‌کند. گزینه دیگر خاموش‌کردن
Privacy Mode است، اما برای قابلیت‌های Ban، Restrict، Delete، Captcha و Join
Request همچنان Administrator بودن لازم است.

## نصب و تست

```bash
composer dump-autoload -o
composer migrate
composer release-three:test
composer check
composer jobs:process
composer telegram:webhook-info
```

خروجی Migration:

```text
[APPLIED] 011_release_three_group_management.sql
Migration completed. New migrations: 1
```

خروجی تست:

```json
{
    "status": "passed",
    "tests": {
        "duration_parser": true,
        "template_renderer": true,
        "database": "passed"
    }
}
```

## تست داخل گروه

1. ربات را Administrator کن.
2. حقوق Delete Messages، Restrict Members، Ban Users و Invite Users را بده.
3. روی پیام یک عضو Reply کن:

```text
/warn تست اخطار
/warnings
/mute 2m تست
/unmute
```

4. قابلیت‌های محافظتی را فعال کن:

```text
/antispam on
/antilink on
/linkwhitelist add github.com
/badwords add عبارت ممنوع
/badwords on
/captcha on
```

5. Worker را تست کن:

```bash
composer jobs:process
```

## محدودیت‌های طراحی

- ربات مالک یا Administrator دیگر را محدود نمی‌کند.
- مدیر ناشناس نمی‌تواند دستور مدیریتی اجرا کند.
- `@username` فقط زمانی Resolve می‌شود که کاربر قبلاً توسط ربات دیده شده باشد.
- Mute فقط در Supergroup قابل اجرا است.
- Slow Mode این نسخه Bot-enforced است، نه Slow Mode بومی Telegram.
- محدودیت‌های Telegram برای حذف پیام‌های قدیمی و Service Messageها پابرجاست.

## Log

```text
storage/logs/group_management.log
storage/logs/jobs.log
storage/logs/webhook.log
```

## فایل‌های جدید

- `app/Modules/GroupManagement/GroupDurationParser.php`
- `app/Modules/GroupManagement/GroupTemplateRenderer.php`
- `app/Modules/GroupManagement/GroupRepository.php`
- `app/Modules/GroupManagement/GroupAuthorization.php`
- `app/Modules/GroupManagement/GroupModerationService.php`
- `app/Modules/GroupManagement/GroupManagementModule.php`
- `app/Modules/GroupManagement/GroupAutomationListener.php`
- `app/Modules/GroupManagement/GroupWorker.php`
- `database/migrations/011_release_three_group_management.sql`
- `scripts/test_release_three.php`
- `PHASE_RELEASE_THREE_README.md`

## فایل‌های کامل جایگزین

- `app/Core/UpdateProcessor.php`
- `app/Modules/Core/CoreModule.php`
- `app/Web/AdminPanelService.php`
- `app/Web/AdminSettingRegistry.php`
- `config/app.php`
- `public/webhook.php`
- `public/admin/index.php`
- `public/admin/assets/admin.css`
- `scripts/process_jobs.php`
- `composer.json`
