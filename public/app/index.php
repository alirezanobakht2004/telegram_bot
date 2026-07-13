<?php

declare(strict_types=1);


header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), serial=(), clipboard-read=()');
header(
    "Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'self' https://telegram.org; "
    . "style-src 'self'; "
    . "img-src 'self' data: https:; "
    . "connect-src 'self'; "
    . "font-src 'self'; "
    . "object-src 'none'; "
    . "base-uri 'none'; "
    . "form-action 'self'; "
    . "frame-ancestors 'self' https://web.telegram.org https://*.telegram.org"
);
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1, viewport-fit=cover"
    >
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#172554">
    <title>جعبه ابزار</title>
    <link rel="stylesheet" href="./assets/app.css?v=6.0.0">
    <script src="https://telegram.org/js/telegram-web-app.js?62"></script>
    <script src="./assets/app.js?v=6.0.0" defer></script>
</head>
<body>
<div id="app" class="app-shell" aria-busy="true">
    <header class="topbar">
        <div class="brand">
            <div class="brand-mark" aria-hidden="true">🧰</div>
            <div>
                <strong id="app-title">جعبه ابزار</strong>
                <span id="user-caption">در حال ورود امن…</span>
            </div>
        </div>
        <button
            id="refresh-button"
            class="icon-button"
            type="button"
            aria-label="تازه‌سازی"
            title="تازه‌سازی"
        >↻</button>
    </header>

    <main id="page" class="page" tabindex="-1">
        <section class="loading-card">
            <div class="spinner" aria-hidden="true"></div>
            <h1>در حال اتصال به Telegram</h1>
            <p>اطلاعات ورود امضاشده در Backend بررسی می‌شود.</p>
        </section>
    </main>

    <nav id="navigation" class="bottom-nav" aria-label="بخش‌های برنامه">
        <button type="button" data-page="dashboard" class="active">
            <span>⌂</span><small>داشبورد</small>
        </button>
        <button type="button" data-page="reminders">
            <span>⏰</span><small>یادآورها</small>
        </button>
        <button type="button" data-page="alerts">
            <span>🔔</span><small>هشدارها</small>
        </button>
        <button type="button" data-page="monitors">
            <span>📡</span><small>مانیتورها</small>
        </button>
        <button type="button" data-page="more">
            <span>•••</span><small>بیشتر</small>
        </button>
    </nav>
</div>

<div id="toast" class="toast" role="status" aria-live="polite"></div>
<div id="modal-root"></div>

<noscript>
    <div class="fatal-card">
        برای استفاده از Mini App باید JavaScript فعال باشد.
    </div>
</noscript>
</body>
</html>
