# انتشار پنج: مسابقه، آزمون و امتیاز

این بسته روی انتشار چهار نصب می‌شود و سیستم کامل مسابقه، Question Bank،
Session، امتیاز، XP، Level، Streak، Achievement و Leaderboard را اضافه می‌کند.

## دستورات کاربران

```text
/quiz
/quiz science
/quiz science hard

/trivia
/trivia geography medium

/mathgame
/mathgame easy
/mathgame hard

/wordgame
/wordgame hard

/dailychallenge

/leaderboard
/leaderboard group
/leaderboard global

/myscore
/achievements
/streak
```

در گروه، `/leaderboard` به‌صورت پیش‌فرض جدول همان گروه را نمایش می‌دهد.
در چت خصوصی، جدول جهانی نمایش داده می‌شود.

## پاسخ با Inline Keyboard

هر سؤال به‌صورت پیام همراه چهار دکمه ارسال می‌شود. `callback_data` فقط شامل
Token تصادفی Session و شماره گزینه است:

```text
quiz:a:<24-hex-token>:<option>
```

طول Callback Data کمتر از 64 بایت نگه داشته می‌شود.

بعد از انتخاب:

- `answerCallbackQuery` اجرا می‌شود.
- پاسخ در یک Transaction اتمی ثبت می‌شود.
- Keyboard حذف می‌شود.
- متن پیام با پاسخ درست، توضیح، امتیاز، XP و Streak به‌روزرسانی می‌شود.
- اگر Edit پیام ممکن نباشد، نتیجه به‌صورت پیام جدید ارسال می‌شود.

## Anti-cheat

لایه‌های ضدتقلب:

```text
Token تصادفی 96 بیتی برای هر Session
اتصال Session به user_id
اتصال Session به chat_id
بررسی message_id
یک Session فعال برای هر کاربر
Transaction نوع BEGIN IMMEDIATE
ثبت فقط یک پاسخ
جلوگیری از Replay
محدودیت زمان پاسخ
بررسی شماره گزینه
جلوگیری از پاسخ کاربر دیگر
یک تلاش برای Daily Challenge
```

Callback Data ورودی قابل اعتماد فرض نمی‌شود و همه اطلاعات از دیتابیس
بازخوانی و اعتبارسنجی می‌شوند.

## بانک سؤال

Migration اولیه شش دسته و هجده سؤال نمونه ایجاد می‌کند:

```text
general      اطلاعات عمومی
science      علوم
geography    جغرافیا
history      تاریخ
technology   فناوری
words        واژگان
```

هر سؤال دارای این فیلدها است:

```text
Category
Question Type
Difficulty
Question Text
4 Options
Correct Option
Explanation
Points
XP Reward
Answer Timeout
Enabled
Usage Statistics
Correct / Incorrect / Timeout Counters
```

نوع سؤال:

```text
trivia
word
```

سؤال‌های `/mathgame` به‌صورت داخلی و بدون API خارجی ساخته می‌شوند.

## سختی

```text
easy
medium
hard
```

نام‌های فارسی نیز در دستورات پشتیبانی می‌شوند:

```text
آسان
متوسط
سخت
```

## امتیاز و XP

امتیاز پاسخ درست از سه بخش تشکیل می‌شود:

```text
Base Points
Time Bonus
Correct Streak Bonus
```

پاسخ نادرست امتیاز ندارد، ولی مقدار قابل‌تنظیم Participation XP دریافت
می‌کند.

تنظیمات Runtime:

```text
modules.quiz_games.scoring.time_bonus_max_percent
modules.quiz_games.scoring.streak_bonus_percent
modules.quiz_games.scoring.participation_xp
modules.quiz_games.scoring.xp_per_level

modules.quiz_games.scoring.points.easy
modules.quiz_games.scoring.points.medium
modules.quiz_games.scoring.points.hard

modules.quiz_games.scoring.xp.easy
modules.quiz_games.scoring.xp.medium
modules.quiz_games.scoring.xp.hard

modules.quiz_games.answer_timeouts.easy
modules.quiz_games.answer_timeouts.medium
modules.quiz_games.answer_timeouts.hard
```

امتیاز، XP و Timeout سؤال‌های Question Bank به‌صورت مستقل نیز از پنل قابل
ویرایش‌اند. تنظیمات Difficulty بالا برای سؤال‌های تولیدی Math Game و مقدار
پیش‌فرض فرم سؤال استفاده می‌شوند.

Level:

```text
level = 1 + floor(xp / xp_per_level)
```

## Streak

دو نوع Streak نگهداری می‌شود:

```text
Correct Streak
Daily Activity Streak
```

Correct Streak با پاسخ نادرست صفر می‌شود.

Daily Streak با ثبت حداقل یک پاسخ در روز افزایش می‌یابد. اگر یک روز کامل
فعالیت وجود نداشته باشد، Streak روزانه از یک شروع می‌شود.

## Achievement

Achievementهای اولیه:

```text
اولین پاسخ
اولین پاسخ درست
10 پاسخ درست
50 پاسخ درست
100 امتیاز
1000 امتیاز
Level 5
5 پاسخ درست پیاپی
3 روز فعالیت پیاپی
7 روز فعالیت پیاپی
10 پاسخ درست ریاضی
10 پاسخ درست واژگان
```

Unlock داخل همان Transaction پاسخ انجام می‌شود و Achievement جدید در پیام
نتیجه نمایش داده می‌شود.

## Daily Challenge

برای هر تاریخ یک سؤال مشترک در `quiz_daily_challenges` ثبت می‌شود.

ویژگی‌ها:

```text
یک سؤال مشترک برای تمام کاربران
یک تلاش برای هر کاربر در هر روز
نتیجه قبلی در اجرای دوباره نمایش داده می‌شود
امتیاز و XP عادی
ثبت Daily Streak
ثبت درصد موفقیت
```

## Leaderboard

### جهانی

```text
/leaderboard global
```

مرتب‌سازی:

```text
Score DESC
XP DESC
Correct Answers DESC
User ID ASC
```

### گروه

```text
/leaderboard group
```

فقط امتیازهایی که داخل همان گروه کسب شده‌اند وارد جدول گروه می‌شوند.

## پنل مدیریت

مسیر:

```text
/admin/?section=quiz
```

قابلیت‌ها:

```text
داشبورد Quiz
ساخت Category
فعال یا غیرفعال‌کردن Category
ساخت سؤال
ویرایش سؤال
فعال یا غیرفعال‌کردن سؤال
چهار گزینه و انتخاب پاسخ درست
تنظیم Difficulty
تنظیم Points
تنظیم XP
تنظیم Answer Timeout
توضیح پاسخ
فیلتر دسته و سختی
Correct Percentage
Timeout Count
Most Difficult Questions
Import CSV
Export CSV
اجرای Maintenance
مشاهده تنظیمات فعلی Scoring
```

تنظیمات عمومی امتیاز و زمان در بخش «تنظیمات» پنل نیز اضافه شده‌اند.

## CSV

### Export

از پنل روی `Export CSV` کلیک کن.

ستون‌ها:

```text
id
category_slug
category_name
category_enabled
question_type
difficulty
question_text
option_a
option_b
option_c
option_d
correct_option
explanation
points
xp_reward
answer_timeout_seconds
enabled
times_served
correct_count
incorrect_count
timeout_count
```

### Import

محدودیت‌ها:

```text
حداکثر فایل: 2 MB
حداکثر سؤال: 1000
Encoding: UTF-8
چهار گزینه اجباری
گزینه‌ها باید متفاوت باشند
Correct Option: A-D یا 1-4
```

اگر `id` موجود و معتبر باشد، سؤال به‌روزرسانی می‌شود. در غیر این صورت سؤال
جدید ساخته می‌شود.

نمونه:

```csv
category_slug,category_name,question_type,difficulty,question_text,option_a,option_b,option_c,option_d,correct_option,explanation,points,xp_reward,answer_timeout_seconds,enabled
animals,حیوانات,trivia,easy,کدام حیوان پستاندار است؟,دلفین,کوسه,اختاپوس,قزل‌آلا,A,دلفین پستاندار است.,10,10,30,1
```

## Worker

Job جدید:

```text
quiz.maintenance
```

وظایف:

```text
منقضی‌کردن Sessionهای بدون پاسخ
ثبت Timeout سؤال
پاک‌سازی Sessionهای قدیمی
```

Scheduled Task جدید لازم نیست:

```bash
php /home/alirezanobakht2004/telegram_bot/scripts/process_jobs.php
```

تنظیمات Worker:

```text
modules.quiz_games.worker.batch_size
modules.quiz_games.worker.interval_seconds
modules.quiz_games.retention_days
```

## Migration

```text
database/migrations/013_release_five_quiz_games.sql
```

جدول‌ها:

```text
quiz_categories
quiz_questions
quiz_question_options
quiz_sessions
quiz_user_scores
quiz_group_scores
quiz_daily_challenges
quiz_daily_attempts
quiz_achievements
quiz_user_achievements
```

Feature Flag:

```text
quiz_games = enabled
rollout_percentage = 100
```

## نصب محلی

محتویات ZIP را داخل پروژه Copy و Replace کن:

```text
G:\Projects\telegram_bot
```

سپس:

```bat
cd /d G:\Projects\telegram_bot

composer dump-autoload -o
composer migrate
composer release-five:test
composer check
composer jobs:process
git status --short
```

خروجی Migration:

```text
[APPLIED] 013_release_five_quiz_games.sql
Migration completed. New migrations: 1
```

خروجی تست:

```json
{
    "status": "passed",
    "tests": {
        "scoring": true,
        "math_generator": true,
        "anti_cheat": true,
        "answer_timeout": true,
        "daily_challenge": true,
        "leaderboards": true,
        "achievements": true,
        "csv_import_export": true,
        "database": "passed"
    }
}
```

Commit:

```bat
git add .
git commit -m "feat: add quiz games scoring and leaderboards"
git push
```

## استقرار Alwaysdata

```bash
cd "$HOME/telegram_bot"

git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
composer migrate
composer release-five:test
composer check
composer jobs:process
```

`callback_query` از انتشار صفر داخل `allowed_updates` فعال است؛ Update Type جدیدی
به این انتشار اضافه نشده است. برای اطمینان:

```bash
composer telegram:webhook-info
```

اگر `callback_query` داخل Allowed Updates نبود:

```bash
composer telegram:set-webhook
```

## تست تلگرام

```text
/quiz
/quiz science hard
/trivia geography
/mathgame easy
/mathgame hard
/wordgame
/dailychallenge
/myscore
/streak
/achievements
/leaderboard global
```

داخل گروه:

```text
/quiz
/mathgame
/leaderboard group
```

فقط کاربری که سؤال را آغاز کرده می‌تواند دکمه پاسخ آن را ثبت کند.

## بررسی دیتابیس

```bash
php -r '
$pdo = new PDO("sqlite:" . getcwd() . "/storage/bot.sqlite");
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

foreach ([
    "quiz_categories",
    "quiz_questions",
    "quiz_question_options",
    "quiz_sessions",
    "quiz_user_scores",
    "quiz_group_scores",
    "quiz_daily_challenges",
    "quiz_daily_attempts",
    "quiz_achievements",
    "quiz_user_achievements"
] as $table) {
    $count = $pdo->query(
        "SELECT COUNT(*) FROM " . $table
    )->fetchColumn();

    echo $table . ": " . $count . PHP_EOL;
}
'
```

جدول برترین‌ها:

```bash
php -r '
$pdo = new PDO("sqlite:" . getcwd() . "/storage/bot.sqlite");
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

print_r($pdo->query("
    SELECT
        user_id,
        score,
        xp,
        level,
        total_answers,
        correct_answers,
        daily_streak
    FROM quiz_user_scores
    ORDER BY score DESC, xp DESC
    LIMIT 20
")->fetchAll());
'
```

سؤال‌های دشوار:

```bash
php -r '
$pdo = new PDO("sqlite:" . getcwd() . "/storage/bot.sqlite");
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

print_r($pdo->query("
    SELECT
        id,
        question_text,
        correct_count,
        incorrect_count,
        timeout_count,
        ROUND(
            correct_count * 100.0
            / NULLIF(
                correct_count + incorrect_count,
                0
            ),
            2
        ) AS correct_percent
    FROM quiz_questions
    WHERE (
        correct_count
        + incorrect_count
        + timeout_count
    ) >= 3
    ORDER BY correct_percent ASC
    LIMIT 20
")->fetchAll());
'
```

## فایل‌های جدید

```text
app/Modules/Quiz/QuizException.php
app/Modules/Quiz/MathQuestionGenerator.php
app/Modules/Quiz/QuizScoring.php
app/Modules/Quiz/QuizRepository.php
app/Modules/Quiz/QuizMaintenanceWorker.php
app/Modules/Quiz/QuizModule.php
app/Modules/Quiz/QuizCsvService.php

database/migrations/013_release_five_quiz_games.sql
scripts/test_release_five.php
PHASE_RELEASE_FIVE_README.md
```

## فایل‌های کامل جایگزین

```text
app/Core/TelegramClient.php
app/Core/CallbackQueryContext.php

app/Modules/Core/CoreModule.php
app/Modules/Profile/ProfileModule.php

app/Web/AdminPanelService.php
app/Web/AdminSettingRegistry.php

config/app.php
public/webhook.php
public/admin/index.php
scripts/process_jobs.php
composer.json
```
