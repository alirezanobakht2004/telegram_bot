# راهنمای کاربران جعبه ابزار v1.0.0

## شروع

```text
/start
/help
/menu
/version
```

`/start` منوی اصلی را دوباره فعال می‌کند. `/help` فهرست دستورهای کامل را
نمایش می‌دهد.

## اطلاعات روزمره

```text
/weather Tehran
/country Iran
/currency 100 USD EUR
/calc 2*(8+3)
```

## یادآورها و هشدارها

```text
/remind 10m خرید شیر
/reminders
/alert weather Tehran rain
/alert temperature Tehran below 0
/subscribe weather Tehran daily 08:00
/alerts
/subscriptions
```

## مانیتورینگ

```text
/status https://example.com
/monitor https://example.com 5m
/monitors
/ssl example.com
/dns example.com
/headers https://example.com
```

## دانش و توسعه

```text
/wiki PHP
/randomwiki
/github php/php-src
/release php/php-src
/issues php/php-src
/watchrelease php/php-src
/json
/regex
/base64
/jwtdecode
/uuid
/hash
```

## امکانات شخصی

```text
/favorite weather Tehran
/favorites
/setshortcut officeweather weather Tehran
/shortcuts
/history
/profile
/settings
```

## فایل و تصویر

روی فایل یا تصویر Reply کنید:

```text
/fileinfo
/filehash
/removeexif
/resize 800
/compress 70
/towebp
/tojpeg
/pdftext
```

تبدیل‌های متنی:

```text
/qr https://example.com
/totxt متن
/tojson متن
/tocsv متن
```

## مسابقه

```text
/quiz
/trivia
/mathgame
/wordgame
/dailychallenge
/leaderboard
/myscore
/achievements
/streak
```

## Mini App

```text
/app
```

Mini App فقط داخل Telegram و با حساب همان کاربر باز می‌شود.

## Inline Mode

در هر چت تایپ کنید:

```text
@SmartToolboxFaBot weather Tehran
@SmartToolboxFaBot country Japan
@SmartToolboxFaBot currency 100 USD EUR
@SmartToolboxFaBot calc 2*(8+3)
@SmartToolboxFaBot wiki PHP
@SmartToolboxFaBot github php/php-src
```
