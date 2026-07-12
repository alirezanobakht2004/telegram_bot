<?php

declare(strict_types=1);

use SmartToolbox\Core\Database;
use SmartToolbox\Core\RuntimeSettings;
use SmartToolbox\Core\TelegramClient;
use SmartToolbox\Web\AdminAuth;
use SmartToolbox\Web\AdminPanelService;
use SmartToolbox\Web\AdminSettingRegistry;

$rootPath = dirname(__DIR__, 2);

$config = require $rootPath
    . '/bootstrap/app.php';

header(
    'Content-Type: text/html; charset=utf-8'
);
header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header(
    'Permissions-Policy: camera=(), microphone=(), geolocation=()'
);
header(
    "Content-Security-Policy: "
    . "default-src 'self'; "
    . "style-src 'self'; "
    . "img-src 'self' data:; "
    . "form-action 'self'; "
    . "frame-ancestors 'none'; "
    . "base-uri 'self'"
);
header(
    'X-Robots-Tag: noindex, nofollow, noarchive'
);

$https = (
    ($_SERVER['HTTPS'] ?? '') !== ''
    && ($_SERVER['HTTPS'] ?? '') !== 'off'
) || mb_strtolower(
    (string) (
        $_SERVER[
            'HTTP_X_FORWARDED_PROTO'
        ] ?? ''
    )
) === 'https';

if ($https) {
    header(
        'Strict-Transport-Security: max-age=31536000; includeSubDomains'
    );
}

$pdo = Database::connect(
    (string) $config->get(
        'database.path'
    )
);

$runtime = new RuntimeSettings(
    $config,
    $pdo
);

$basePath = rtrim(
    (string) $config->get(
        'web_admin.base_path',
        '/admin'
    ),
    '/'
);

if ($basePath === '') {
    $basePath = '/admin';
}

$auth = new AdminAuth(
    pdo: $pdo,
    passwordHash: (string) $config->get(
        'web_admin.password_hash',
        ''
    ),
    sessionName: (string) $config->get(
        'web_admin.session_name',
        'smart_toolbox_admin'
    ),
    idleSeconds: (int) $config->get(
        'web_admin.session_idle_seconds',
        3600
    ),
    absoluteSeconds: (int) $config->get(
        'web_admin.session_absolute_seconds',
        43200
    ),
    maxAttempts: (int) $config->get(
        'web_admin.login_max_attempts',
        5
    ),
    windowSeconds: (int) $config->get(
        'web_admin.login_window_seconds',
        900
    ),
    blockSeconds: (int) $config->get(
        'web_admin.login_block_seconds',
        900
    ),
    secureCookie: $https,
    cookiePath: $basePath
);

$auth->startSession();

$ipAddress = (string) (
    $_SERVER['REMOTE_ADDR']
    ?? 'unknown'
);

$userAgent = (string) (
    $_SERVER['HTTP_USER_AGENT']
    ?? ''
);

$identity = 'web-admin';

$service = new AdminPanelService(
    pdo: $pdo,
    runtime: $runtime,
    registry: new AdminSettingRegistry(),
    telegram: new TelegramClient(
        (string) $config->get(
            'telegram.token'
        )
    ),
    databasePath: (string) $config->get(
        'database.path'
    ),
    cacheDirectory: (string) $config->get(
        'paths.cache'
    ) . '/api',
    logsDirectory: (string) $config->get(
        'paths.logs'
    ),
    backupsDirectory: (string) $config->get(
        'paths.backups'
    )
);

$h = static fn (
    mixed $value
): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$number = static fn (
    int|float|string $value
): string => is_numeric($value)
    ? number_format((float) $value)
    : (string) $value;

$bytes = static function (
    int|float|string $value
): string {
    $size = is_numeric($value)
        ? (float) $value
        : 0.0;

    if ($size <= 0) {
        return '0 B';
    }

    $units = [
        'B',
        'KB',
        'MB',
        'GB',
        'TB',
    ];

    $power = min(
        (int) floor(
            log($size, 1024)
        ),
        count($units) - 1
    );

    return number_format(
        $size / (1024 ** $power),
        $power === 0 ? 0 : 2
    ) . ' ' . $units[$power];
};

$redirect = static function (
    string $url
): never {
    header('Location: ' . $url);
    exit;
};

$flash = static function (
    string $type,
    string $message
): void {
    $_SESSION['admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
};

$consumeFlash =
    static function (): ?array {
        $value = $_SESSION[
            'admin_flash'
        ] ?? null;

        unset($_SESSION['admin_flash']);

        return is_array($value)
            ? $value
            : null;
    };

if (
    !(bool) $config->get(
        'web_admin.enabled',
        true
    )
) {
    http_response_code(503);

    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>پنل غیرفعال</title><body><h1>پنل مدیریت غیرفعال است.</h1></body></html>';
    exit;
}

if (
    $auth->authenticated($userAgent)
    && ($_GET['download_backup'] ?? '')
        !== ''
) {
    try {
        $path = $service->backupPath(
            (string) $_GET[
                'download_backup'
            ]
        );

        header(
            'Content-Type: application/octet-stream'
        );
        header(
            'Content-Disposition: attachment; filename="'
            . basename($path)
            . '"'
        );
        header(
            'Content-Length: '
            . (string) filesize($path)
        );

        readfile($path);
        exit;
    } catch (Throwable) {
        http_response_code(404);
        echo 'Backup not found.';
        exit;
    }
}

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    === 'POST'
    && ($_POST['action'] ?? '')
        === 'login'
) {
    if (
        !$auth->validateCsrf(
            $_POST['csrf'] ?? null
        )
    ) {
        $loginError =
            'درخواست ورود معتبر نیست.';
    } else {
        $result = $auth->login(
            (string) (
                $_POST['password'] ?? ''
            ),
            $ipAddress,
            $userAgent
        );

        if ($result['success']) {
            $service->audit(
                $identity,
                'auth.login',
                'web-panel',
                [],
                $ipAddress,
                $userAgent
            );

            $redirect(
                $basePath . '/'
            );
        }

        $loginError =
            $result['error'];
    }
}

if (!$auth->authenticated($userAgent)) {
    $csrf = $auth->csrfToken();
    $configured = $auth->configured();
    ?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>ورود به مدیریت جعبه ابزار</title>
    <link
        rel="stylesheet"
        href="<?= $h($basePath) ?>/assets/admin.css"
    >
</head>
<body class="login-page">
<main class="login-card">
    <div class="brand-mark">🛡</div>
    <h1>پنل مدیریت جعبه ابزار</h1>
    <p class="muted">
        ورود امن به مدیریت ربات
    </p>

    <?php if (!$configured): ?>
        <div class="alert alert-error">
            رمز پنل تنظیم نشده است. ابتدا
            Password Hash را در
            <code>config/local.php</code>
            قرار بده.
        </div>
    <?php endif; ?>

    <?php if (isset($loginError)): ?>
        <div class="alert alert-error">
            <?= $h($loginError) ?>
        </div>
    <?php endif; ?>

    <form
        method="post"
        action="<?= $h($basePath) ?>/"
        class="stack"
    >
        <input
            type="hidden"
            name="csrf"
            value="<?= $h($csrf) ?>"
        >
        <input
            type="hidden"
            name="action"
            value="login"
        >

        <label>
            رمز عبور
            <input
                type="password"
                name="password"
                required
                autocomplete="current-password"
                autofocus
                <?= $configured ? '' : 'disabled' ?>
            >
        </label>

        <button
            type="submit"
            class="button primary"
            <?= $configured ? '' : 'disabled' ?>
        >
            ورود
        </button>
    </form>
</main>
</body>
</html>
<?php
    exit;
}

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    === 'POST'
) {
    if (
        !$auth->validateCsrf(
            $_POST['csrf'] ?? null
        )
    ) {
        http_response_code(419);
        echo 'CSRF validation failed.';
        exit;
    }

    $action = (string) (
        $_POST['action'] ?? ''
    );

    try {
        switch ($action) {
            case 'logout':
                $service->audit(
                    $identity,
                    'auth.logout',
                    'web-panel',
                    [],
                    $ipAddress,
                    $userAgent
                );
                $auth->logout();
                $redirect(
                    $basePath . '/'
                );

            case 'save_setting':
                $service->saveSetting(
                    (string) (
                        $_POST['key'] ?? ''
                    ),
                    $_POST['value'] ?? null,
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'تنظیم ذخیره شد و از درخواست بعدی اعمال می‌شود.'
                );
                break;

            case 'reset_setting':
                $deleted =
                    $service->resetSetting(
                        (string) (
                            $_POST['key']
                            ?? ''
                        ),
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    $deleted
                        ? 'Override حذف شد.'
                        : 'این تنظیم Override نداشت.'
                );
                break;

            case 'reset_all_settings':
                $deleted =
                    $service
                        ->resetAllSettings(
                            $identity,
                            $ipAddress,
                            $userAgent
                        );
                $flash(
                    'success',
                    "{$deleted} تنظیم بازنشانی شد."
                );
                break;

            case 'clear_cache':
                $deleted =
                    $service->clearCache(
                        (string) (
                            $_POST['scope']
                            ?? ''
                        ),
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    "{$deleted} فایل کش حذف شد."
                );
                break;

            case 'prune_cache':
                $deleted =
                    $service->pruneCache(
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    "{$deleted} فایل منقضی یا خراب حذف شد."
                );
                break;

            case 'user_block':
            case 'user_unblock':
                $service->setUserBlocked(
                    (int) (
                        $_POST['telegram_id']
                        ?? 0
                    ),
                    $action === 'user_block',
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    $action === 'user_block'
                        ? 'کاربر مسدود شد.'
                        : 'کاربر از مسدودی خارج شد.'
                );
                break;

            case 'chat_block':
            case 'chat_unblock':
                $service->setChatBlocked(
                    (int) (
                        $_POST['telegram_id']
                        ?? 0
                    ),
                    $action === 'chat_block',
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    $action === 'chat_block'
                        ? 'چت مسدود شد.'
                        : 'چت از مسدودی خارج شد.'
                );
                break;

            case 'create_broadcast':
                $admins = (array)
                    $config->get(
                        'admins',
                        []
                    );
                $adminUserId =
                    isset($admins[0])
                        ? (int) $admins[0]
                        : 0;

                $id =
                    $service->createBroadcast(
                        (string) (
                            $_POST['message']
                            ?? ''
                        ),
                        $adminUserId,
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    "صف ارسال با شناسه {$id} ساخته شد."
                );
                break;

            case 'process_broadcast':
                $summary =
                    $service
                        ->processBroadcast(
                            (int) (
                                $_POST[
                                    'broadcast_id'
                                ] ?? 0
                            ),
                            $identity,
                            $ipAddress,
                            $userAgent
                        );
                $flash(
                    'success',
                    'Batch: '
                    . $summary['sent_batch']
                    . ' موفق، '
                    . $summary[
                        'failed_batch'
                    ]
                    . ' ناموفق، '
                    . $summary['pending']
                    . ' باقی‌مانده.'
                );
                break;

            case 'cancel_broadcast':
                $service->cancelBroadcast(
                    (int) (
                        $_POST[
                            'broadcast_id'
                        ] ?? 0
                    ),
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'ارسال لغو شد.'
                );
                break;

            case 'retry_broadcast':
                $count =
                    $service->retryBroadcast(
                        (int) (
                            $_POST[
                                'broadcast_id'
                            ] ?? 0
                        ),
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    "{$count} گیرنده دوباره وارد صف شد."
                );
                break;

            case 'process_reminders':
                $result =
                    $service->processDueReminders(
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    'Worker اجرا شد: '
                    . $result['sent']
                    . ' ارسال، '
                    . $result['failed']
                    . ' ناموفق، '
                    . $result['retried']
                    . ' Retry.'
                );
                break;

            case 'cancel_reminder':
                $service->cancelReminder(
                    (int) (
                        $_POST['reminder_id']
                        ?? 0
                    ),
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'یادآور لغو شد.'
                );
                break;

            case 'retry_reminder':
                $service->retryReminder(
                    (int) (
                        $_POST['reminder_id']
                        ?? 0
                    ),
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'یادآور دوباره وارد صف شد.'
                );
                break;

            case 'clear_log':
                $service->clearLog(
                    (string) (
                        $_POST['log_name']
                        ?? ''
                    ),
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'Log پاک شد.'
                );
                break;

            case 'cleanup_expired':
                $result =
                    $service->cleanupExpired(
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    'پاک‌سازی: '
                    . $result['states']
                    . ' State، '
                    . $result[
                        'rate_limits'
                    ]
                    . ' Rate Limit، '
                    . $result[
                        'login_attempts'
                    ]
                    . ' رکورد ورود.'
                );
                break;

            case 'optimize_database':
                $service->optimizeDatabase(
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'SQLite بهینه‌سازی شد.'
                );
                break;

            case 'create_backup':
                $name =
                    $service
                        ->createDatabaseBackup(
                            $identity,
                            $ipAddress,
                            $userAgent
                        );
                $flash(
                    'success',
                    "Backup ساخته شد: {$name}"
                );
                break;

            case 'delete_backup':
                $service->deleteBackup(
                    (string) (
                        $_POST[
                            'backup_name'
                        ] ?? ''
                    ),
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'Backup حذف شد.'
                );
                break;

            default:
                throw new RuntimeException(
                    'عملیات ناشناخته است.'
                );
        }

        $auth->rotateCsrf();
    } catch (Throwable $exception) {
        $flash(
            'error',
            $exception->getMessage()
        );
    }

    $returnSection = preg_replace(
        '/[^a-z_]/',
        '',
        (string) (
            $_POST['return_section']
            ?? 'dashboard'
        )
    ) ?: 'dashboard';

    $redirect(
        $basePath
        . '/?section='
        . rawurlencode(
            $returnSection
        )
    );
}

$sections = [
    'dashboard',
    'settings',
    'cache',
    'users',
    'chats',
    'broadcasts',
    'reminders',
    'logs',
    'system',
    'audit',
];

$section = (string) (
    $_GET['section'] ?? 'dashboard'
);

if (
    !in_array(
        $section,
        $sections,
        true
    )
) {
    $section = 'dashboard';
}

$csrf = $auth->csrfToken();
$flashMessage = $consumeFlash();
$stats = $service->dashboard();

$navigation = [
    'dashboard' => ['📊', 'داشبورد'],
    'settings' => ['⚙️', 'تنظیمات'],
    'cache' => ['🗃', 'کش'],
    'users' => ['👥', 'کاربران'],
    'chats' => ['💬', 'چت‌ها'],
    'broadcasts' => ['📣', 'ارسال همگانی'],
    'reminders' => ['⏰', 'یادآورها'],
    'logs' => ['🧾', 'لاگ‌ها'],
    'system' => ['🩺', 'سیستم'],
    'audit' => ['🔎', 'Audit'],
];

?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title><?= $h($navigation[$section][1]) ?> | مدیریت جعبه ابزار</title>
    <link
        rel="stylesheet"
        href="<?= $h($basePath) ?>/assets/admin.css"
    >
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a
            class="brand"
            href="<?= $h($basePath) ?>/"
        >
            <span class="brand-icon">🧰</span>
            <span>
                <strong>جعبه ابزار</strong>
                <small>مدیریت ربات</small>
            </span>
        </a>

        <nav class="nav">
            <?php foreach (
                $navigation
                as $key => [$icon, $label]
            ): ?>
                <a
                    class="<?= $section === $key ? 'active' : '' ?>"
                    href="<?= $h($basePath) ?>/?section=<?= $h($key) ?>"
                >
                    <span><?= $h($icon) ?></span>
                    <?= $h($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <form
            method="post"
            action="<?= $h($basePath) ?>/"
            class="logout-form"
        >
            <input
                type="hidden"
                name="csrf"
                value="<?= $h($csrf) ?>"
            >
            <input
                type="hidden"
                name="action"
                value="logout"
            >
            <button
                type="submit"
                class="button danger ghost"
            >
                خروج امن
            </button>
        </form>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <h1>
                    <?= $h($navigation[$section][1]) ?>
                </h1>
                <p>
                    آمار، کنترل و تنظیم Runtime ربات
                </p>
            </div>
            <div class="topbar-meta">
                <span class="status-dot"></span>
                <?= $h(date('Y-m-d H:i')) ?>
            </div>
        </header>

        <?php if ($flashMessage !== null): ?>
            <div class="alert alert-<?= $h($flashMessage['type']) ?>">
                <?= $h($flashMessage['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($section === 'dashboard'): ?>
            <?php
            $stats = $service->dashboard();
            $activity = $service->activity();
            $maximum = 1;

            foreach ($activity as $row) {
                $maximum = max(
                    $maximum,
                    $row['users'],
                    $row['updates']
                );
            }

            $cards = [
                ['👥', 'کل کاربران', $stats['users_total']],
                ['🟢', 'فعال ۲۴ ساعت', $stats['users_active_24h']],
                ['📅', 'فعال ۷ روز', $stats['users_active_7d']],
                ['✨', 'کاربر جدید امروز', $stats['users_new_24h']],
                ['📨', 'کل درخواست‌ها', $stats['requests_total']],
                ['🔄', 'کل Updateها', $stats['updates_total']],
                ['⚠️', 'Update ناموفق', $stats['updates_failed']],
                ['💬', 'چت فعال', $stats['chats_active']],
                ['📣', 'Broadcast فعال', $stats['broadcasts_active']],
                ['⏰', 'یادآور فعال', $stats['reminders_pending']],
                ['⚙️', 'Override فعال', $stats['runtime_overrides']],
                ['🗃', 'فایل کش', $stats['cache_files']],
                ['💾', 'حجم دیتابیس', $bytes($stats['database_bytes'])],
            ];
            ?>

            <section class="metrics">
                <?php foreach (
                    $cards
                    as [$icon, $label, $value]
                ): ?>
                    <article class="metric-card">
                        <span class="metric-icon">
                            <?= $h($icon) ?>
                        </span>
                        <div>
                            <small>
                                <?= $h($label) ?>
                            </small>
                            <strong>
                                <?= $h(
                                    is_numeric($value)
                                        ? $number($value)
                                        : $value
                                ) ?>
                            </strong>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="panel">
                <h2>فعالیت ۱۴ روز اخیر</h2>
                <div class="activity-chart">
                    <?php foreach ($activity as $row): ?>
                        <div class="activity-column">
                            <div class="bars">
                                <span
                                    class="bar users h-<?= (int) round(10 * $row['users'] / $maximum) ?>"
                                ></span>
                                <span
                                    class="bar updates h-<?= (int) round(10 * $row['updates'] / $maximum) ?>"
                                ></span>
                            </div>
                            <small>
                                <?= $h(substr($row['date'], 5)) ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="legend">
                    <span>
                        <i class="legend-users"></i>
                        کاربران جدید
                    </span>
                    <span>
                        <i class="legend-updates"></i>
                        Updateها
                    </span>
                </div>
            </section>

            <section class="grid two">
                <article class="panel">
                    <h2>کاربران و چت‌ها</h2>
                    <dl class="detail-list">
                        <div>
                            <dt>کاربران مسدود</dt>
                            <dd><?= $number($stats['users_blocked']) ?></dd>
                        </div>
                        <div>
                            <dt>چت خصوصی</dt>
                            <dd><?= $number($stats['chats_private']) ?></dd>
                        </div>
                        <div>
                            <dt>گروه و سوپرگروه</dt>
                            <dd><?= $number($stats['chats_groups']) ?></dd>
                        </div>
                        <div>
                            <dt>چت مسدود مدیریتی</dt>
                            <dd><?= $number($stats['chats_blocked']) ?></dd>
                        </div>
                        <div>
                            <dt>تنظیمات کاربران</dt>
                            <dd><?= $number($stats['preferences']) ?></dd>
                        </div>
                    </dl>
                </article>

                <article class="panel">
                    <h2>پردازش و منابع</h2>
                    <dl class="detail-list">
                        <div>
                            <dt>Update تکمیل‌شده</dt>
                            <dd><?= $number($stats['updates_completed']) ?></dd>
                        </div>
                        <div>
                            <dt>Update امروز</dt>
                            <dd><?= $number($stats['updates_today']) ?></dd>
                        </div>
                        <div>
                            <dt>State فعال</dt>
                            <dd><?= $number($stats['active_states']) ?></dd>
                        </div>
                        <div>
                            <dt>Rate Limit فعال</dt>
                            <dd><?= $number($stats['active_rate_limits']) ?></dd>
                        </div>
                        <div>
                            <dt>حجم کش</dt>
                            <dd><?= $h($bytes($stats['cache_bytes'])) ?></dd>
                        </div>
                        <div>
                            <dt>دیسک آزاد</dt>
                            <dd><?= $h($bytes($stats['disk_free_bytes'])) ?></dd>
                        </div>
                        <div>
                            <dt>PHP</dt>
                            <dd><?= $h($stats['php_version']) ?></dd>
                        </div>
                    </dl>
                </article>
            </section>

        <?php elseif ($section === 'settings'): ?>
            <?php
            $groups = $service->settings();
            ?>
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>تنظیمات Runtime</h2>
                        <p>
                            بدون Deploy و از درخواست بعدی اعمال می‌شوند.
                        </p>
                    </div>
                    <form
                        method="post"
                        action="<?= $h($basePath) ?>/"
                    >
                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= $h($csrf) ?>"
                        >
                        <input
                            type="hidden"
                            name="action"
                            value="reset_all_settings"
                        >
                        <input
                            type="hidden"
                            name="return_section"
                            value="settings"
                        >
                        <button
                            class="button danger"
                            type="submit"
                        >
                            بازنشانی همه
                        </button>
                    </form>
                </div>
                <div class="notice">
                    Token تلگرام، Webhook Secret و رمز پنل عمداً
                    قابل مشاهده یا تغییر نیستند.
                </div>
            </section>

            <?php foreach (
                $groups
                as $group => $items
            ): ?>
                <section class="panel">
                    <h2><?= $h($group) ?></h2>
                    <div class="settings-grid">
                        <?php foreach ($items as $item): ?>
                            <article
                                class="setting-card <?= $item['overridden'] ? 'overridden' : '' ?>"
                            >
                                <div class="setting-head">
                                    <div>
                                        <h3>
                                            <?= $h($item['label']) ?>
                                        </h3>
                                        <code>
                                            <?= $h($item['key']) ?>
                                        </code>
                                    </div>
                                    <span
                                        class="badge <?= $item['overridden'] ? 'warning' : 'neutral' ?>"
                                    >
                                        <?= $item['overridden'] ? 'Runtime' : 'پیش‌فرض' ?>
                                    </span>
                                </div>

                                <p>
                                    <?= $h($item['help']) ?>
                                </p>
                                <small>
                                    مقدار فایل:
                                    <strong>
                                        <?= $h(
                                            is_bool($item['base'])
                                                ? (
                                                    $item['base']
                                                        ? 'فعال'
                                                        : 'غیرفعال'
                                                )
                                                : $item['base']
                                        ) ?>
                                    </strong>
                                </small>

                                <form
                                    method="post"
                                    action="<?= $h($basePath) ?>/"
                                    class="setting-form"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf"
                                        value="<?= $h($csrf) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="save_setting"
                                    >
                                    <input
                                        type="hidden"
                                        name="return_section"
                                        value="settings"
                                    >
                                    <input
                                        type="hidden"
                                        name="key"
                                        value="<?= $h($item['key']) ?>"
                                    >

                                    <?php if ($item['type'] === 'bool'): ?>
                                        <select name="value">
                                            <option
                                                value="1"
                                                <?= $item['effective'] ? 'selected' : '' ?>
                                            >
                                                فعال
                                            </option>
                                            <option
                                                value="0"
                                                <?= !$item['effective'] ? 'selected' : '' ?>
                                            >
                                                غیرفعال
                                            </option>
                                        </select>
                                    <?php else: ?>
                                        <input
                                            type="number"
                                            name="value"
                                            value="<?= $h($item['effective']) ?>"
                                            min="<?= $h($item['min']) ?>"
                                            max="<?= $h($item['max']) ?>"
                                            required
                                        >
                                    <?php endif; ?>

                                    <button
                                        class="button primary"
                                        type="submit"
                                    >
                                        ذخیره
                                    </button>
                                </form>

                                <?php if ($item['overridden']): ?>
                                    <form
                                        method="post"
                                        action="<?= $h($basePath) ?>/"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf"
                                            value="<?= $h($csrf) ?>"
                                        >
                                        <input
                                            type="hidden"
                                            name="action"
                                            value="reset_setting"
                                        >
                                        <input
                                            type="hidden"
                                            name="return_section"
                                            value="settings"
                                        >
                                        <input
                                            type="hidden"
                                            name="key"
                                            value="<?= $h($item['key']) ?>"
                                        >
                                        <button
                                            class="button ghost"
                                            type="submit"
                                        >
                                            مقدار فایل
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

        <?php elseif ($section === 'cache'): ?>
            <?php
            $cacheStats =
                $service->cacheStats();
            ?>
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>مدیریت کش</h2>
                        <p>
                            بعد از تغییر TTL، برای اعمال فوری کش همان
                            ماژول را پاک کن.
                        </p>
                    </div>
                    <form
                        method="post"
                        action="<?= $h($basePath) ?>/"
                    >
                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= $h($csrf) ?>"
                        >
                        <input
                            type="hidden"
                            name="action"
                            value="prune_cache"
                        >
                        <input
                            type="hidden"
                            name="return_section"
                            value="cache"
                        >
                        <button
                            class="button secondary"
                            type="submit"
                        >
                            حذف منقضی‌ها
                        </button>
                    </form>
                </div>
            </section>

            <section class="metrics">
                <?php foreach (
                    $cacheStats
                    as $scope => $item
                ): ?>
                    <article class="metric-card cache-card">
                        <span class="metric-icon">
                            🗃
                        </span>
                        <div>
                            <small>
                                <?= $h($item['label']) ?>
                            </small>
                            <strong>
                                <?= $number($item['files']) ?>
                                فایل
                            </strong>
                            <span>
                                <?= $h($bytes($item['bytes'])) ?>
                                ·
                                <?= $number($item['expired']) ?>
                                منقضی
                            </span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="panel">
                <h2>پاک‌سازی انتخابی</h2>
                <div class="action-grid">
                    <?php foreach (
                        $cacheStats
                        as $scope => $item
                    ): ?>
                        <form
                            method="post"
                            action="<?= $h($basePath) ?>/"
                        >
                            <input
                                type="hidden"
                                name="csrf"
                                value="<?= $h($csrf) ?>"
                            >
                            <input
                                type="hidden"
                                name="action"
                                value="clear_cache"
                            >
                            <input
                                type="hidden"
                                name="return_section"
                                value="cache"
                            >
                            <input
                                type="hidden"
                                name="scope"
                                value="<?= $h($scope) ?>"
                            >
                            <button
                                class="button <?= $scope === 'all' ? 'danger' : 'secondary' ?>"
                                type="submit"
                            >
                                پاک‌کردن
                                <?= $h($item['label']) ?>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
                <p class="muted">
                    کش‌های قدیمی بدون Metadata فقط با پاک‌سازی کل کش
                    حذف می‌شوند.
                </p>
            </section>

        <?php elseif ($section === 'users'): ?>
            <?php
            $search = trim(
                (string) (
                    $_GET['q'] ?? ''
                )
            );
            $page = max(
                1,
                (int) (
                    $_GET['page'] ?? 1
                )
            );
            $users = $service->users(
                $search,
                $page
            );
            ?>
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>کاربران</h2>
                        <p>
                            <?= $number($users['total']) ?>
                            کاربر
                        </p>
                    </div>
                    <form
                        method="get"
                        action="<?= $h($basePath) ?>/"
                        class="search-form"
                    >
                        <input
                            type="hidden"
                            name="section"
                            value="users"
                        >
                        <input
                            type="search"
                            name="q"
                            value="<?= $h($search) ?>"
                            placeholder="ID، نام یا username"
                        >
                        <button
                            class="button primary"
                            type="submit"
                        >
                            جست‌وجو
                        </button>
                    </form>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>کاربر</th>
                            <th>ID</th>
                            <th>زبان</th>
                            <th>درخواست</th>
                            <th>حضور</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (
                            $users['rows']
                            as $user
                        ): ?>
                            <?php
                            $name = trim(
                                (string) (
                                    $user['first_name']
                                    ?? ''
                                )
                                . ' '
                                . (string) (
                                    $user['last_name']
                                    ?? ''
                                )
                            );
                            ?>
                            <tr>
                                <td>
                                    <strong>
                                        <?= $h($name !== '' ? $name : 'بدون نام') ?>
                                    </strong>
                                    <small>
                                        <?= $h(
                                            ($user['username'] ?? '') !== ''
                                                ? '@' . $user['username']
                                                : '—'
                                        ) ?>
                                    </small>
                                </td>
                                <td>
                                    <code>
                                        <?= $h($user['telegram_id']) ?>
                                    </code>
                                </td>
                                <td>
                                    <?= $h($user['language_code'] ?? '—') ?>
                                </td>
                                <td>
                                    <?= $number((int) $user['request_count']) ?>
                                </td>
                                <td>
                                    <small>
                                        اول:
                                        <?= $h($user['first_seen_at']) ?>
                                    </small>
                                    <small>
                                        آخر:
                                        <?= $h($user['last_seen_at']) ?>
                                    </small>
                                </td>
                                <td>
                                    <span
                                        class="badge <?= (int) $user['is_blocked'] === 1 ? 'danger' : 'success' ?>"
                                    >
                                        <?= (int) $user['is_blocked'] === 1 ? 'مسدود' : 'فعال' ?>
                                    </span>
                                </td>
                                <td>
                                    <form
                                        method="post"
                                        action="<?= $h($basePath) ?>/"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf"
                                            value="<?= $h($csrf) ?>"
                                        >
                                        <input
                                            type="hidden"
                                            name="return_section"
                                            value="users"
                                        >
                                        <input
                                            type="hidden"
                                            name="telegram_id"
                                            value="<?= $h($user['telegram_id']) ?>"
                                        >
                                        <input
                                            type="hidden"
                                            name="action"
                                            value="<?= (int) $user['is_blocked'] === 1 ? 'user_unblock' : 'user_block' ?>"
                                        >
                                        <button
                                            class="button small <?= (int) $user['is_blocked'] === 1 ? 'success' : 'danger' ?>"
                                            type="submit"
                                        >
                                            <?= (int) $user['is_blocked'] === 1 ? 'رفع مسدودی' : 'مسدودکردن' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    <?php for (
                        $index = 1;
                        $index <= $users['pages'];
                        $index++
                    ): ?>
                        <a
                            class="<?= $index === $users['page'] ? 'active' : '' ?>"
                            href="<?= $h($basePath) ?>/?section=users&amp;q=<?= rawurlencode($search) ?>&amp;page=<?= $index ?>"
                        >
                            <?= $index ?>
                        </a>
                    <?php endfor; ?>
                </div>
            </section>

        <?php elseif ($section === 'chats'): ?>
            <?php
            $search = trim(
                (string) (
                    $_GET['q'] ?? ''
                )
            );
            $page = max(
                1,
                (int) (
                    $_GET['page'] ?? 1
                )
            );
            $chats = $service->chats(
                $search,
                $page
            );
            ?>
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>چت‌ها</h2>
                        <p>
                            <?= $number($chats['total']) ?>
                            چت
                        </p>
                    </div>
                    <form
                        method="get"
                        action="<?= $h($basePath) ?>/"
                        class="search-form"
                    >
                        <input
                            type="hidden"
                            name="section"
                            value="chats"
                        >
                        <input
                            type="search"
                            name="q"
                            value="<?= $h($search) ?>"
                            placeholder="ID، عنوان یا username"
                        >
                        <button
                            class="button primary"
                            type="submit"
                        >
                            جست‌وجو
                        </button>
                    </form>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>چت</th>
                            <th>ID</th>
                            <th>نوع</th>
                            <th>درخواست</th>
                            <th>آخرین حضور</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (
                            $chats['rows']
                            as $chat
                        ): ?>
                            <?php
                            $title = trim(
                                (string) (
                                    $chat['title']
                                    ?? ''
                                )
                            );

                            if ($title === '') {
                                $title = trim(
                                    (string) (
                                        $chat[
                                            'first_name'
                                        ] ?? ''
                                    )
                                    . ' '
                                    . (string) (
                                        $chat[
                                            'last_name'
                                        ] ?? ''
                                    )
                                );
                            }
                            ?>
                            <tr>
                                <td>
                                    <strong>
                                        <?= $h($title !== '' ? $title : 'بدون عنوان') ?>
                                    </strong>
                                    <small>
                                        <?= $h(
                                            ($chat['username'] ?? '') !== ''
                                                ? '@' . $chat['username']
                                                : '—'
                                        ) ?>
                                    </small>
                                </td>
                                <td>
                                    <code>
                                        <?= $h($chat['telegram_id']) ?>
                                    </code>
                                </td>
                                <td>
                                    <?= $h($chat['type']) ?>
                                </td>
                                <td>
                                    <?= $number((int) $chat['request_count']) ?>
                                </td>
                                <td>
                                    <?= $h($chat['last_seen_at']) ?>
                                </td>
                                <td>
                                    <?php if ((int) $chat['admin_blocked'] === 1): ?>
                                        <span class="badge danger">
                                            مسدود مدیریتی
                                        </span>
                                    <?php elseif ((int) $chat['is_active'] === 1): ?>
                                        <span class="badge success">
                                            فعال
                                        </span>
                                    <?php else: ?>
                                        <span class="badge warning">
                                            غیرفعال
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form
                                        method="post"
                                        action="<?= $h($basePath) ?>/"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf"
                                            value="<?= $h($csrf) ?>"
                                        >
                                        <input
                                            type="hidden"
                                            name="return_section"
                                            value="chats"
                                        >
                                        <input
                                            type="hidden"
                                            name="telegram_id"
                                            value="<?= $h($chat['telegram_id']) ?>"
                                        >
                                        <input
                                            type="hidden"
                                            name="action"
                                            value="<?= (int) $chat['admin_blocked'] === 1 ? 'chat_unblock' : 'chat_block' ?>"
                                        >
                                        <button
                                            class="button small <?= (int) $chat['admin_blocked'] === 1 ? 'success' : 'danger' ?>"
                                            type="submit"
                                        >
                                            <?= (int) $chat['admin_blocked'] === 1 ? 'رفع مسدودی' : 'مسدودکردن' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    <?php for (
                        $index = 1;
                        $index <= $chats['pages'];
                        $index++
                    ): ?>
                        <a
                            class="<?= $index === $chats['page'] ? 'active' : '' ?>"
                            href="<?= $h($basePath) ?>/?section=chats&amp;q=<?= rawurlencode($search) ?>&amp;page=<?= $index ?>"
                        >
                            <?= $index ?>
                        </a>
                    <?php endfor; ?>
                </div>
            </section>

        <?php elseif ($section === 'broadcasts'): ?>
            <?php
            $broadcasts =
                $service->broadcasts();
            ?>
            <section class="panel">
                <h2>ساخت پیام همگانی</h2>
                <p>
                    فقط برای چت‌های خصوصی فعال و مسدودنشده
                    صف ساخته می‌شود.
                </p>
                <form
                    method="post"
                    action="<?= $h($basePath) ?>/"
                    class="stack"
                >
                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= $h($csrf) ?>"
                    >
                    <input
                        type="hidden"
                        name="action"
                        value="create_broadcast"
                    >
                    <input
                        type="hidden"
                        name="return_section"
                        value="broadcasts"
                    >
                    <textarea
                        name="message"
                        rows="6"
                        maxlength="<?= $h((int) $runtime->get('modules.admin.max_broadcast_length', 3000)) ?>"
                        required
                        placeholder="متن پیام مدیریت"
                    ></textarea>
                    <button
                        class="button primary"
                        type="submit"
                    >
                        ساخت صف ارسال
                    </button>
                </form>
            </section>

            <section class="panel">
                <h2>صف‌ها و تاریخچه</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>متن</th>
                            <th>وضعیت</th>
                            <th>پیشرفت</th>
                            <th>زمان</th>
                            <th>عملیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (
                            $broadcasts
                            as $broadcast
                        ): ?>
                            <?php
                            $message = (string)
                                $broadcast[
                                    'message_text'
                                ];
                            $preview = mb_substr(
                                $message,
                                0,
                                120
                            );
                            ?>
                            <tr>
                                <td>
                                    <code>
                                        <?= $h($broadcast['id']) ?>
                                    </code>
                                </td>
                                <td>
                                    <?= nl2br($h($preview)) ?>
                                    <?= mb_strlen($message) > 120 ? '…' : '' ?>
                                </td>
                                <td>
                                    <span class="badge neutral">
                                        <?= $h($broadcast['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= $number((int) $broadcast['sent_count']) ?>
                                    موفق /
                                    <?= $number((int) $broadcast['failed_count']) ?>
                                    ناموفق /
                                    <?= $number((int) $broadcast['total_recipients']) ?>
                                    کل
                                </td>
                                <td>
                                    <small>
                                        <?= $h($broadcast['created_at']) ?>
                                    </small>
                                </td>
                                <td class="actions">
                                    <?php if (
                                        in_array(
                                            $broadcast['status'],
                                            [
                                                'pending',
                                                'running',
                                            ],
                                            true
                                        )
                                    ): ?>
                                        <form
                                            method="post"
                                            action="<?= $h($basePath) ?>/"
                                        >
                                            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                                            <input type="hidden" name="action" value="process_broadcast">
                                            <input type="hidden" name="return_section" value="broadcasts">
                                            <input type="hidden" name="broadcast_id" value="<?= $h($broadcast['id']) ?>">
                                            <button class="button small primary" type="submit">
                                                پردازش Batch
                                            </button>
                                        </form>

                                        <form
                                            method="post"
                                            action="<?= $h($basePath) ?>/"
                                        >
                                            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                                            <input type="hidden" name="action" value="cancel_broadcast">
                                            <input type="hidden" name="return_section" value="broadcasts">
                                            <input type="hidden" name="broadcast_id" value="<?= $h($broadcast['id']) ?>">
                                            <button class="button small danger" type="submit">
                                                لغو
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (
                                        (int) $broadcast[
                                            'failed_count'
                                        ] > 0
                                    ): ?>
                                        <form
                                            method="post"
                                            action="<?= $h($basePath) ?>/"
                                        >
                                            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                                            <input type="hidden" name="action" value="retry_broadcast">
                                            <input type="hidden" name="return_section" value="broadcasts">
                                            <input type="hidden" name="broadcast_id" value="<?= $h($broadcast['id']) ?>">
                                            <button class="button small secondary" type="submit">
                                                Retry خطاها
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>


        <?php elseif ($section === 'reminders'): ?>
            <?php
            $status = (string) (
                $_GET['status'] ?? 'all'
            );

            $page = max(
                1,
                (int) (
                    $_GET['page'] ?? 1
                )
            );

            $statusLabels = [
                'all' => 'همه',
                'pending' => 'در صف',
                'processing' => 'در حال پردازش',
                'sent' => 'ارسال‌شده',
                'failed' => 'ناموفق',
                'cancelled' => 'لغوشده',
            ];

            if (
                !array_key_exists(
                    $status,
                    $statusLabels
                )
            ) {
                $status = 'all';
            }

            $reminders = $service->reminders(
                $status,
                $page
            );

            $lastRun =
                $service->lastReminderWorkerRun();
            ?>
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>مدیریت یادآورها</h2>
                        <p>
                            صف ارسال، تاریخچه، Retry و اجرای دستی Worker
                        </p>
                    </div>

                    <form
                        method="post"
                        action="<?= $h($basePath) ?>/"
                    >
                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= $h($csrf) ?>"
                        >
                        <input
                            type="hidden"
                            name="action"
                            value="process_reminders"
                        >
                        <input
                            type="hidden"
                            name="return_section"
                            value="reminders"
                        >
                        <button
                            class="button primary"
                            type="submit"
                        >
                            اجرای Worker الان
                        </button>
                    </form>
                </div>

                <?php if ($lastRun !== null): ?>
                    <div class="notice">
                        آخرین اجرا:
                        <?= $h($lastRun['started_at']) ?>
                        · وضعیت:
                        <?= $h($lastRun['status']) ?>
                        · دریافت:
                        <?= $number((int) $lastRun['claimed_count']) ?>
                        · ارسال:
                        <?= $number((int) $lastRun['sent_count']) ?>
                        · ناموفق:
                        <?= $number((int) $lastRun['failed_count']) ?>
                        · Retry:
                        <?= $number((int) $lastRun['retried_count']) ?>
                    </div>
                <?php else: ?>
                    <div class="notice">
                        Worker هنوز اجرا نشده است.
                    </div>
                <?php endif; ?>
            </section>

            <section class="metrics">
                <article class="metric-card">
                    <span class="metric-icon">⏳</span>
                    <div>
                        <small>در صف</small>
                        <strong>
                            <?= $number($stats['reminders_pending']) ?>
                        </strong>
                    </div>
                </article>

                <article class="metric-card">
                    <span class="metric-icon">🔔</span>
                    <div>
                        <small>سررسیدشده</small>
                        <strong>
                            <?= $number($stats['reminders_due']) ?>
                        </strong>
                    </div>
                </article>

                <article class="metric-card">
                    <span class="metric-icon">✅</span>
                    <div>
                        <small>ارسال‌شده</small>
                        <strong>
                            <?= $number($stats['reminders_sent']) ?>
                        </strong>
                    </div>
                </article>

                <article class="metric-card">
                    <span class="metric-icon">⚠️</span>
                    <div>
                        <small>ناموفق</small>
                        <strong>
                            <?= $number($stats['reminders_failed']) ?>
                        </strong>
                    </div>
                </article>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>فهرست یادآورها</h2>
                        <p>
                            <?= $number($reminders['total']) ?>
                            رکورد
                        </p>
                    </div>

                    <form
                        method="get"
                        action="<?= $h($basePath) ?>/"
                        class="search-form"
                    >
                        <input
                            type="hidden"
                            name="section"
                            value="reminders"
                        >
                        <select name="status">
                            <?php foreach (
                                $statusLabels
                                as $statusKey =>
                                    $statusLabel
                            ): ?>
                                <option
                                    value="<?= $h($statusKey) ?>"
                                    <?= $status === $statusKey ? 'selected' : '' ?>
                                >
                                    <?= $h($statusLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button
                            class="button primary"
                            type="submit"
                        >
                            فیلتر
                        </button>
                    </form>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>کاربر / چت</th>
                            <th>متن</th>
                            <th>زمان</th>
                            <th>وضعیت</th>
                            <th>تلاش</th>
                            <th>خطا</th>
                            <th>عملیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (
                            $reminders['rows']
                            as $reminder
                        ): ?>
                            <?php
                            $displayName = trim(
                                (string) (
                                    $reminder[
                                        'first_name'
                                    ] ?? ''
                                )
                                . ' '
                                . (string) (
                                    $reminder[
                                        'last_name'
                                    ] ?? ''
                                )
                            );

                            if ($displayName === '') {
                                $displayName =
                                    'بدون نام';
                            }

                            try {
                                $localDate = (
                                    new DateTimeImmutable(
                                        '@'
                                        . (int) $reminder[
                                            'scheduled_at'
                                        ]
                                    )
                                )->setTimezone(
                                    new DateTimeZone(
                                        (string) $reminder[
                                            'timezone'
                                        ]
                                    )
                                )->format(
                                    'Y-m-d H:i'
                                );
                            } catch (Throwable) {
                                $localDate = date(
                                    'Y-m-d H:i',
                                    (int) $reminder[
                                        'scheduled_at'
                                    ]
                                );
                            }
                            ?>
                            <tr>
                                <td>
                                    <code>
                                        #<?= $h($reminder['id']) ?>
                                    </code>
                                </td>
                                <td>
                                    <strong>
                                        <?= $h($displayName) ?>
                                    </strong>
                                    <small>
                                        User:
                                        <?= $h($reminder['user_id']) ?>
                                        · Chat:
                                        <?= $h($reminder['chat_id']) ?>
                                    </small>
                                </td>
                                <td>
                                    <?= nl2br(
                                        $h(
                                            mb_substr(
                                                (string) $reminder[
                                                    'reminder_text'
                                                ],
                                                0,
                                                220
                                            )
                                        )
                                    ) ?>
                                </td>
                                <td>
                                    <?= $h($localDate) ?>
                                    <small>
                                        <?= $h($reminder['timezone']) ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge neutral">
                                        <?= $h(
                                            $statusLabels[
                                                $reminder['status']
                                            ] ?? $reminder['status']
                                        ) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= $number(
                                        (int) $reminder['attempts']
                                    ) ?>
                                </td>
                                <td>
                                    <small>
                                        <?= $h(
                                            mb_substr(
                                                (string) (
                                                    $reminder[
                                                        'last_error'
                                                    ] ?? ''
                                                ),
                                                0,
                                                180
                                            )
                                        ) ?>
                                    </small>
                                </td>
                                <td class="actions">
                                    <?php if (
                                        in_array(
                                            $reminder['status'],
                                            [
                                                'pending',
                                                'failed',
                                            ],
                                            true
                                        )
                                    ): ?>
                                        <form
                                            method="post"
                                            action="<?= $h($basePath) ?>/"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf"
                                                value="<?= $h($csrf) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="cancel_reminder"
                                            >
                                            <input
                                                type="hidden"
                                                name="return_section"
                                                value="reminders"
                                            >
                                            <input
                                                type="hidden"
                                                name="reminder_id"
                                                value="<?= $h($reminder['id']) ?>"
                                            >
                                            <button
                                                class="button small danger"
                                                type="submit"
                                            >
                                                لغو
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (
                                        $reminder['status']
                                        === 'failed'
                                    ): ?>
                                        <form
                                            method="post"
                                            action="<?= $h($basePath) ?>/"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf"
                                                value="<?= $h($csrf) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="retry_reminder"
                                            >
                                            <input
                                                type="hidden"
                                                name="return_section"
                                                value="reminders"
                                            >
                                            <input
                                                type="hidden"
                                                name="reminder_id"
                                                value="<?= $h($reminder['id']) ?>"
                                            >
                                            <button
                                                class="button small secondary"
                                                type="submit"
                                            >
                                                Retry
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    <?php for (
                        $index = 1;
                        $index <= $reminders['pages'];
                        $index++
                    ): ?>
                        <a
                            class="<?= $index === $reminders['page'] ? 'active' : '' ?>"
                            href="<?= $h($basePath) ?>/?section=reminders&amp;status=<?= rawurlencode($status) ?>&amp;page=<?= $index ?>"
                        >
                            <?= $index ?>
                        </a>
                    <?php endfor; ?>
                </div>
            </section>

        <?php elseif ($section === 'logs'): ?>
            <?php
            $logs = $service->logs();
            $selectedLog = (string) (
                $_GET['log']
                ?? ($logs[0]['name'] ?? '')
            );
            $logContents =
                $selectedLog !== ''
                    ? $service->readLog(
                        $selectedLog
                    )
                    : '';
            ?>
            <section class="grid logs-layout">
                <article class="panel">
                    <h2>فایل‌های Log</h2>
                    <div class="log-list">
                        <?php if ($logs === []): ?>
                            <p class="muted">
                                هنوز Log ایجاد نشده است.
                            </p>
                        <?php endif; ?>

                        <?php foreach ($logs as $log): ?>
                            <a
                                class="<?= $selectedLog === $log['name'] ? 'active' : '' ?>"
                                href="<?= $h($basePath) ?>/?section=logs&amp;log=<?= rawurlencode($log['name']) ?>"
                            >
                                <strong>
                                    <?= $h($log['name']) ?>
                                </strong>
                                <small>
                                    <?= $h($bytes($log['bytes'])) ?>
                                    ·
                                    <?= $h($log['modified_at']) ?>
                                </small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="panel log-viewer">
                    <div class="panel-header">
                        <div>
                            <h2>
                                <?= $h($selectedLog !== '' ? $selectedLog : 'Log') ?>
                            </h2>
                            <p>
                                بخش انتهایی فایل؛ حداکثر ۱۰۰ کیلوبایت
                            </p>
                        </div>

                        <?php if ($selectedLog !== ''): ?>
                            <form
                                method="post"
                                action="<?= $h($basePath) ?>/"
                            >
                                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                                <input type="hidden" name="action" value="clear_log">
                                <input type="hidden" name="return_section" value="logs">
                                <input type="hidden" name="log_name" value="<?= $h($selectedLog) ?>">
                                <button class="button danger" type="submit">
                                    پاک‌کردن Log
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <pre><?= $h($logContents !== '' ? $logContents : 'Log خالی است.') ?></pre>
                </article>
            </section>

        <?php elseif ($section === 'system'): ?>
            <?php
            $system = $service->system();
            $backups = $service->backups();
            ?>
            <section class="grid two">
                <article class="panel">
                    <h2>سلامت سرور</h2>
                    <dl class="detail-list">
                        <div><dt>PHP</dt><dd><?= $h($system['php_version']) ?></dd></div>
                        <div><dt>SAPI</dt><dd><?= $h($system['php_sapi']) ?></dd></div>
                        <div><dt>Timezone</dt><dd><?= $h($system['timezone']) ?></dd></div>
                        <div><dt>زمان سرور</dt><dd><?= $h($system['server_time']) ?></dd></div>
                        <div><dt>حافظه فعلی</dt><dd><?= $h($bytes($system['memory_usage_bytes'])) ?></dd></div>
                        <div><dt>Peak حافظه</dt><dd><?= $h($bytes($system['memory_peak_bytes'])) ?></dd></div>
                        <div><dt>Memory limit</dt><dd><?= $h($system['memory_limit']) ?></dd></div>
                        <div><dt>دیسک آزاد</dt><dd><?= $h($bytes($system['disk_free_bytes'])) ?></dd></div>
                        <div><dt>کل دیسک</dt><dd><?= $h($bytes($system['disk_total_bytes'])) ?></dd></div>
                    </dl>
                </article>

                <article class="panel">
                    <h2>سلامت SQLite</h2>
                    <dl class="detail-list">
                        <div><dt>Quick check</dt><dd><?= $h($system['sqlite_quick_check']) ?></dd></div>
                        <div><dt>Journal mode</dt><dd><?= $h($system['sqlite_journal_mode']) ?></dd></div>
                        <div><dt>حجم دیتابیس</dt><dd><?= $h($bytes($system['database_bytes'])) ?></dd></div>
                        <div><dt>Page count</dt><dd><?= $number($system['sqlite_page_count']) ?></dd></div>
                        <div><dt>Page size</dt><dd><?= $h($bytes($system['sqlite_page_size'])) ?></dd></div>
                        <div><dt>State منقضی</dt><dd><?= $number($system['expired_states']) ?></dd></div>
                        <div><dt>Rate Limit منقضی</dt><dd><?= $number($system['expired_rate_limits']) ?></dd></div>
                        <div><dt>رکورد تلاش ورود</dt><dd><?= $number($system['login_attempt_rows']) ?></dd></div>
                    </dl>
                </article>
            </section>

            <section class="panel">
                <h2>عملیات نگهداری</h2>
                <div class="action-grid">
                    <form method="post" action="<?= $h($basePath) ?>/">
                        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                        <input type="hidden" name="action" value="cleanup_expired">
                        <input type="hidden" name="return_section" value="system">
                        <button class="button secondary" type="submit">
                            پاک‌سازی منقضی‌ها
                        </button>
                    </form>

                    <form method="post" action="<?= $h($basePath) ?>/">
                        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                        <input type="hidden" name="action" value="optimize_database">
                        <input type="hidden" name="return_section" value="system">
                        <button class="button secondary" type="submit">
                            VACUUM و ANALYZE
                        </button>
                    </form>

                    <form method="post" action="<?= $h($basePath) ?>/">
                        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                        <input type="hidden" name="action" value="create_backup">
                        <input type="hidden" name="return_section" value="system">
                        <button class="button primary" type="submit">
                            ساخت Backup
                        </button>
                    </form>
                </div>
            </section>

            <section class="panel">
                <h2>Backupها</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>فایل</th>
                            <th>حجم</th>
                            <th>زمان</th>
                            <th>عملیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (
                            $backups
                            as $backup
                        ): ?>
                            <tr>
                                <td>
                                    <code>
                                        <?= $h($backup['name']) ?>
                                    </code>
                                </td>
                                <td>
                                    <?= $h($bytes($backup['bytes'])) ?>
                                </td>
                                <td>
                                    <?= $h($backup['modified_at']) ?>
                                </td>
                                <td class="actions">
                                    <a
                                        class="button small primary"
                                        href="<?= $h($basePath) ?>/?download_backup=<?= rawurlencode($backup['name']) ?>"
                                    >
                                        دانلود
                                    </a>
                                    <form
                                        method="post"
                                        action="<?= $h($basePath) ?>/"
                                    >
                                        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                                        <input type="hidden" name="action" value="delete_backup">
                                        <input type="hidden" name="return_section" value="system">
                                        <input type="hidden" name="backup_name" value="<?= $h($backup['name']) ?>">
                                        <button class="button small danger" type="submit">
                                            حذف
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php elseif ($section === 'audit'): ?>
            <?php
            $auditRows =
                $service->auditLogs();
            ?>
            <section class="panel">
                <h2>Audit Log مدیریت</h2>
                <p>
                    تغییر تنظیمات، کش، مسدودی، Broadcast و عملیات
                    سیستم ثبت می‌شوند.
                </p>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>زمان</th>
                            <th>عملیات</th>
                            <th>هدف</th>
                            <th>IP</th>
                            <th>جزئیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (
                            $auditRows
                            as $row
                        ): ?>
                            <tr>
                                <td>
                                    <?= $h($row['created_at']) ?>
                                </td>
                                <td>
                                    <code>
                                        <?= $h($row['action']) ?>
                                    </code>
                                </td>
                                <td>
                                    <?= $h($row['target']) ?>
                                </td>
                                <td>
                                    <code>
                                        <?= $h($row['ip_address']) ?>
                                    </code>
                                </td>
                                <td>
                                    <small>
                                        <?= $h($row['details_json']) ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
