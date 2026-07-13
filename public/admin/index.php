<?php

declare(strict_types=1);

use SmartToolbox\Core\Database;
use SmartToolbox\Core\RuntimeSettings;
use SmartToolbox\Core\TelegramClient;
use SmartToolbox\Web\AdminAuth;
use SmartToolbox\Web\AdminPanelService;
use SmartToolbox\Web\AdminSettingRegistry;
use SmartToolbox\Web\AnalyticsCsvExporter;

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
    $auth->authenticated($userAgent)
    && ($_GET['export_analytics'] ?? '')
        !== ''
) {
    try {
        $dataset = (string) $_GET[
            'export_analytics'
        ];

        $days = max(
            1,
            min(
                365,
                (int) (
                    $_GET['days'] ?? 30
                )
            )
        );

        $filename = sprintf(
            'analytics-%s-%dd-%s.csv',
            preg_replace(
                '/[^a-z_]/',
                '',
                $dataset
            ) ?: 'export',
            $days,
            date('Ymd-His')
        );

        header(
            'Content-Type: text/csv; charset=utf-8'
        );
        header(
            'Content-Disposition: attachment; filename="'
            . $filename
            . '"'
        );

        $stream = fopen(
            'php://output',
            'wb'
        );

        if ($stream === false) {
            throw new RuntimeException(
                'CSV output stream could not be opened.'
            );
        }

        (new AnalyticsCsvExporter($pdo))->stream(
            $dataset,
            $days,
            $stream
        );

        fclose($stream);
        exit;
    } catch (Throwable $exception) {
        http_response_code(400);
        echo $h($exception->getMessage());
        exit;
    }
}

if (
    $auth->authenticated($userAgent)
    && ($_GET['export_quiz'] ?? '')
        === 'questions'
) {
    try {
        $filename = 'quiz-questions-'
            . date('Ymd-His')
            . '.csv';

        header(
            'Content-Type: text/csv; charset=utf-8'
        );
        header(
            'Content-Disposition: attachment; filename="'
            . $filename
            . '"'
        );

        $stream = fopen(
            'php://output',
            'wb'
        );

        if ($stream === false) {
            throw new RuntimeException(
                'CSV output stream could not be opened.'
            );
        }

        $service->streamQuizCsv($stream);
        fclose($stream);
        exit;
    } catch (Throwable $exception) {
        http_response_code(400);
        echo $h($exception->getMessage());
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
    <script
        src="<?= $h($basePath) ?>/assets/admin.js"
        defer
    ></script>
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

            case 'revoke_mini_app_session':
                $service->revokeMiniAppSession(
                    (int) (
                        $_POST['session_id'] ?? 0
                    ),
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'Session Mini App لغو شد.'
                );
                break;

            case 'revoke_mini_app_user_sessions':
                $count = $service
                    ->revokeMiniAppUserSessions(
                        (int) (
                            $_POST['user_id'] ?? 0
                        ),
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    "{$count} Session کاربر لغو شد."
                );
                break;

            case 'cleanup_mini_app':
                $result = $service
                    ->cleanupMiniApp(
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    'پاک‌سازی Mini App انجام شد: '
                    . $result['sessions']
                    . ' Session، '
                    . $result['rate_limits']
                    . ' Rate Limit و '
                    . $result['audit']
                    . ' Audit.'
                );
                break;

            case 'save_quiz_category':
                $categoryId =
                    $service->saveQuizCategory(
                        (int) (
                            $_POST['category_id']
                            ?? 0
                        ),
                        (string) (
                            $_POST['slug'] ?? ''
                        ),
                        (string) (
                            $_POST['name'] ?? ''
                        ),
                        (string) (
                            $_POST['description']
                            ?? ''
                        ),
                        (string) (
                            $_POST['enabled'] ?? '0'
                        ) === '1',
                        (int) (
                            $_POST['sort_order']
                            ?? 0
                        ),
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    "دسته #{$categoryId} ذخیره شد."
                );
                break;

            case 'toggle_quiz_category':
                $service->setQuizCategoryEnabled(
                    (int) (
                        $_POST['category_id']
                        ?? 0
                    ),
                    (string) (
                        $_POST['enabled'] ?? '0'
                    ) === '1',
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'وضعیت دسته تغییر کرد.'
                );
                break;

            case 'save_quiz_question':
                $questionId =
                    $service->saveQuizQuestion(
                        (int) (
                            $_POST['question_id']
                            ?? 0
                        ),
                        (int) (
                            $_POST['category_id']
                            ?? 0
                        ),
                        (string) (
                            $_POST['question_type']
                            ?? 'trivia'
                        ),
                        (string) (
                            $_POST['difficulty']
                            ?? 'medium'
                        ),
                        (string) (
                            $_POST['question_text']
                            ?? ''
                        ),
                        [
                            (string) (
                                $_POST['option_a']
                                ?? ''
                            ),
                            (string) (
                                $_POST['option_b']
                                ?? ''
                            ),
                            (string) (
                                $_POST['option_c']
                                ?? ''
                            ),
                            (string) (
                                $_POST['option_d']
                                ?? ''
                            ),
                        ],
                        (int) (
                            $_POST['correct_option']
                            ?? 0
                        ),
                        (string) (
                            $_POST['explanation']
                            ?? ''
                        ),
                        (int) (
                            $_POST['points'] ?? 10
                        ),
                        (int) (
                            $_POST['xp_reward'] ?? 10
                        ),
                        (int) (
                            $_POST[
                                'answer_timeout_seconds'
                            ] ?? 30
                        ),
                        (string) (
                            $_POST['enabled'] ?? '0'
                        ) === '1',
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    "سؤال #{$questionId} ذخیره شد."
                );
                break;

            case 'toggle_quiz_question':
                $service->setQuizQuestionEnabled(
                    (int) (
                        $_POST['question_id']
                        ?? 0
                    ),
                    (string) (
                        $_POST['enabled'] ?? '0'
                    ) === '1',
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'وضعیت سؤال تغییر کرد.'
                );
                break;

            case 'import_quiz_csv':
                $upload = $_FILES[
                    'quiz_csv'
                ] ?? null;

                if (
                    !is_array($upload)
                    || (int) (
                        $upload['error']
                        ?? UPLOAD_ERR_NO_FILE
                    ) !== UPLOAD_ERR_OK
                    || !is_uploaded_file(
                        (string) (
                            $upload['tmp_name']
                            ?? ''
                        )
                    )
                ) {
                    throw new RuntimeException(
                        'آپلود CSV معتبر نیست.'
                    );
                }

                $result =
                    $service->importQuizCsv(
                        (string) $upload[
                            'tmp_name'
                        ],
                        (string) (
                            $upload['name']
                            ?? 'questions.csv'
                        ),
                        (int) (
                            $upload['size'] ?? 0
                        ),
                        $identity,
                        $ipAddress,
                        $userAgent
                    );

                $flash(
                    'success',
                    'CSV وارد شد: '
                    . $result['imported']
                    . ' سؤال جدید، '
                    . $result['updated']
                    . ' سؤال به‌روزشده و '
                    . $result['categories']
                    . ' دسته.'
                );
                break;

            case 'process_quiz_maintenance':
                $result =
                    $service
                        ->processQuizMaintenance(
                            $identity,
                            $ipAddress,
                            $userAgent
                        );
                $flash(
                    'success',
                    'نگهداری Quiz اجرا شد: '
                    . $result['expired']
                    . ' Session منقضی و '
                    . $result['pruned']
                    . ' Session پاک شد.'
                );
                break;

            case 'save_group_settings':
                $service->saveGroupSettings(
                    (int) (
                        $_POST['chat_id'] ?? 0
                    ),
                    $_POST,
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'تنظیمات گروه ذخیره شد.'
                );
                break;

            case 'process_group_worker':
                $result =
                    $service->processGroupWorker(
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    'Worker گروه اجرا شد: '
                    . $result['sanctions_lifted']
                    . ' محدودیت پایان‌یافته، '
                    . $result['captchas_expired']
                    . ' کپچای منقضی و '
                    . $result['pruned']
                    . ' رکورد پاک‌سازی شد.'
                );
                break;

            case 'resolve_group_join':
                $service->resolveGroupJoinRequest(
                    (int) (
                        $_POST['chat_id'] ?? 0
                    ),
                    (int) (
                        $_POST['request_id'] ?? 0
                    ),
                    (string) (
                        $_POST['decision'] ?? ''
                    ),
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'درخواست عضویت پردازش شد.'
                );
                break;

            case 'create_group_invite':
                $inviteId =
                    $service->createGroupInvite(
                        (int) (
                            $_POST['chat_id'] ?? 0
                        ),
                        (string) (
                            $_POST['link_name'] ?? ''
                        ),
                        (int) (
                            $_POST[
                                'expire_minutes'
                            ] ?? 0
                        ),
                        (int) (
                            $_POST[
                                'member_limit'
                            ] ?? 0
                        ),
                        (string) (
                            $_POST[
                                'creates_join_request'
                            ] ?? '0'
                        ) === '1',
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    "لینک دعوت #{$inviteId} ساخته شد."
                );
                break;

            case 'lift_group_sanction':
                $service->liftGroupSanction(
                    (int) (
                        $_POST['chat_id'] ?? 0
                    ),
                    (int) (
                        $_POST['sanction_id']
                        ?? 0
                    ),
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'محدودیت کاربر برداشته شد.'
                );
                break;

            case 'cancel_group_captcha':
                $service->cancelGroupCaptcha(
                    (int) (
                        $_POST['chat_id'] ?? 0
                    ),
                    (int) (
                        $_POST['captcha_id']
                        ?? 0
                    ),
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'کپچا لغو و محدودیت عضو برداشته شد.'
                );
                break;

            case 'revoke_group_invite':
                $service->revokeGroupInvite(
                    (int) (
                        $_POST['chat_id'] ?? 0
                    ),
                    (int) (
                        $_POST['invite_id'] ?? 0
                    ),
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'لینک دعوت لغو شد.'
                );
                break;

            case 'clear_group_warnings':
                $count =
                    $service->clearGroupWarnings(
                        (int) (
                            $_POST['chat_id'] ?? 0
                        ),
                        (int) (
                            $_POST[
                                'target_user_id'
                            ] ?? 0
                        ),
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    "{$count} اخطار فعال پاک شد."
                );
                break;

            case 'update_group_list':
                $affected =
                    $service->updateGroupList(
                        (int) (
                            $_POST['chat_id'] ?? 0
                        ),
                        (string) (
                            $_POST['list_type']
                            ?? ''
                        ),
                        (string) (
                            $_POST['operation']
                            ?? ''
                        ),
                        (string) (
                            $_POST['value'] ?? ''
                        ),
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    "فهرست گروه به‌روزرسانی شد؛ {$affected} رکورد تغییر کرد."
                );
                break;

            case 'automation_status':
                $service->setAutomationStatus(
                    (string) ($_POST['automation_type'] ?? ''),
                    (int) ($_POST['automation_id'] ?? 0),
                    (string) ($_POST['automation_status'] ?? ''),
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'وضعیت رکورد خودکار تغییر کرد.'
                );
                break;

            case 'cleanup_analytics':
                $result =
                    $service->cleanupAnalytics(
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    'پاک‌سازی Analytics انجام شد؛ مجموع حذف: '
                    . array_sum($result)
                );
                break;

            case 'save_feature_flag':
                $service->saveFeatureFlag(
                    (string) (
                        $_POST['flag_key']
                        ?? ''
                    ),
                    (string) (
                        $_POST['enabled']
                        ?? '0'
                    ) === '1',
                    (int) (
                        $_POST[
                            'rollout_percentage'
                        ] ?? 100
                    ),
                    (string) (
                        $_POST['description']
                        ?? ''
                    ),
                    $identity,
                    $ipAddress,
                    $userAgent
                );
                $flash(
                    'success',
                    'Feature Flag ذخیره شد.'
                );
                break;

            case 'reset_feature_flag':
                $deleted =
                    $service->resetFeatureFlag(
                        (string) (
                            $_POST['flag_key']
                            ?? ''
                        ),
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    $deleted
                        ? 'Feature Flag به مقدار config برگشت.'
                        : 'Override برای این Feature وجود نداشت.'
                );
                break;

            case 'replay_dead_letter':
                $jobId =
                    $service->replayDeadLetter(
                        (int) (
                            $_POST[
                                'dead_letter_id'
                            ] ?? 0
                        ),
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    'success',
                    "Job جدید #{$jobId} ساخته شد."
                );
                break;

            case 'cancel_job':
                $cancelled =
                    $service->cancelJob(
                        (int) (
                            $_POST['job_id']
                            ?? 0
                        ),
                        $identity,
                        $ipAddress,
                        $userAgent
                    );
                $flash(
                    $cancelled
                        ? 'success'
                        : 'error',
                    $cancelled
                        ? 'Job لغو شد.'
                        : 'Job قابل لغو پیدا نشد.'
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

    $returnUrl = $basePath
        . '/?section='
        . rawurlencode(
            $returnSection
        );

    $returnChatId = (int) (
        $_POST['return_chat_id'] ?? 0
    );

    if (
        $returnSection === 'group_management'
        && $returnChatId !== 0
    ) {
        $returnUrl .= '&chat_id='
            . rawurlencode(
                (string) $returnChatId
            );
    }

    $redirect($returnUrl);
}

$sections = [
    'dashboard',
    'analytics',
    'features',
    'settings',
    'cache',
    'users',
    'chats',
    'broadcasts',
    'reminders',
    'group_management',
    'quiz',
    'mini_app',
    'automation',
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
$stats = in_array(
    $section,
    [
        'dashboard',
        'reminders',
        'group_management',
        'quiz',
        'mini_app',
        'automation',
    ],
    true
)
    ? $service->dashboard()
    : [];

$navigation = [
    'dashboard' => ['📊', 'داشبورد'],
    'analytics' => ['📈', 'Analytics'],
    'features' => ['🚩', 'Feature Flags'],
    'settings' => ['⚙️', 'تنظیمات'],
    'cache' => ['🗃', 'کش'],
    'users' => ['👥', 'کاربران'],
    'chats' => ['💬', 'چت‌ها'],
    'broadcasts' => ['📣', 'ارسال همگانی'],
    'reminders' => ['⏰', 'یادآورها'],
    'group_management' => ['🛡', 'مدیریت گروه‌ها'],
    'quiz' => ['🎯', 'مسابقه و آزمون'],
    'mini_app' => ['📱', 'Mini App'],
    'automation' => ['🔔', 'هشدار و مانیتور'],
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
    <script
        src="<?= $h($basePath) ?>/assets/admin.js"
        defer
    ></script>
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
                ['🔔', 'هشدار فعال', $stats['alerts_active']],
                ['📬', 'اشتراک فعال', $stats['subscriptions_active']],
                ['📡', 'مانیتور فعال', $stats['monitors_active']],
                ['❌', 'مانیتور Down', $stats['monitors_down']],
                ['🛡', 'گروه مدیریت‌شده', $stats['managed_groups']],
                ['⚠️', 'اخطار فعال گروه', $stats['group_warnings_active']],
                ['🔒', 'محدودیت فعال گروه', $stats['group_sanctions_active']],
                ['🧩', 'کپچای در انتظار', $stats['group_captchas_pending']],
                ['🚪', 'درخواست عضویت', $stats['group_join_requests_pending']],
                ['❓', 'سؤال فعال', $stats['quiz_questions_enabled']],
                ['🎮', 'بازیکن مسابقه', $stats['quiz_players']],
                ['✅', 'پاسخ امروز', $stats['quiz_answers_today']],
                ['⏱', 'Quiz فعال', $stats['quiz_active_sessions']],
                ['📱', 'Session فعال Mini App', $stats['mini_app_active_sessions']],
                ['🔐', 'ورود Mini App امروز', $stats['mini_app_auth_today']],
                ['🚫', 'خطای Mini App امروز', $stats['mini_app_failures_today']],
                ['📈', 'رویداد امروز', $stats['usage_events_today']],
                ['🧵', 'Job فعال', $stats['jobs_queued']],
                ['💀', 'Dead Letter', $stats['dead_letters']],
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

        <?php elseif ($section === 'analytics'): ?>
            <?php
            $analyticsDays = max(
                1,
                min(
                    365,
                    (int) (
                        $_GET['days'] ?? 30
                    )
                )
            );

            $analytics = $service->analytics(
                $analyticsDays
            );

            $analyticsSummary =
                $analytics['summary'];

            $dailyRows = array_slice(
                $analytics['daily'],
                -14
            );

            $dailyMaximum = 1;

            foreach ($dailyRows as $row) {
                $dailyMaximum = max(
                    $dailyMaximum,
                    (int) $row['events'],
                    (int) $row['users']
                );
            }

            $analyticsCards = [
                [
                    '📈',
                    'رویدادها',
                    $analyticsSummary['events'],
                ],
                [
                    '👥',
                    'کاربران یکتا',
                    $analyticsSummary['unique_users'],
                ],
                [
                    '✅',
                    'نرخ موفقیت',
                    $analyticsSummary['success_rate'] . '%',
                ],
                [
                    '⏱',
                    'میانگین زمان',
                    $analyticsSummary['avg_duration_ms'] . ' ms',
                ],
                [
                    '🌐',
                    'API Call',
                    $analyticsSummary['api_calls'],
                ],
                [
                    '⚠️',
                    'API Failure',
                    $analyticsSummary['api_failures'],
                ],
                [
                    '🗃',
                    'Cache Hit Rate',
                    $analyticsSummary['cache_hit_rate'] . '%',
                ],
                [
                    '💾',
                    'حجم پاسخ API',
                    $bytes(
                        $analyticsSummary[
                            'api_response_bytes'
                        ]
                    ),
                ],
            ];
            ?>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>تحلیل رفتار و عملکرد</h2>
                        <p>
                            رویدادها، فرمان‌ها، Cache، API،
                            Retention و صف Job
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
                            value="analytics"
                        >
                        <select name="days">
                            <?php foreach (
                                [7, 14, 30, 60, 90, 180, 365]
                                as $optionDays
                            ): ?>
                                <option
                                    value="<?= $optionDays ?>"
                                    <?= $analyticsDays === $optionDays ? 'selected' : '' ?>
                                >
                                    <?= $optionDays ?> روز
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button
                            class="button primary"
                            type="submit"
                        >
                            اعمال
                        </button>
                    </form>
                </div>

                <div class="action-grid">
                    <?php foreach (
                        [
                            'daily' => 'روزانه',
                            'commands' => 'فرمان‌ها',
                            'modules' => 'ماژول‌ها',
                            'api' => 'API',
                            'cache' => 'Cache',
                            'errors' => 'خطاها',
                            'raw' => 'Raw Events',
                        ]
                        as $dataset => $label
                    ): ?>
                        <a
                            class="button secondary"
                            href="<?= $h($basePath) ?>/?export_analytics=<?= $h($dataset) ?>&amp;days=<?= $analyticsDays ?>"
                        >
                            CSV <?= $h($label) ?>
                        </a>
                    <?php endforeach; ?>

                    <form
                        method="post"
                        action="<?= $h($basePath) ?>/"
                        data-confirm="داده‌های منقضی Analytics پاک شوند؟"
                    >
                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= $h($csrf) ?>"
                        >
                        <input
                            type="hidden"
                            name="action"
                            value="cleanup_analytics"
                        >
                        <input
                            type="hidden"
                            name="return_section"
                            value="analytics"
                        >
                        <button
                            class="button danger"
                            type="submit"
                        >
                            پاک‌سازی Retention
                        </button>
                    </form>
                </div>
            </section>

            <section class="metrics">
                <?php foreach (
                    $analyticsCards
                    as [$icon, $label, $value]
                ): ?>
                    <article class="metric-card">
                        <span class="metric-icon">
                            <?= $h($icon) ?>
                        </span>
                        <div>
                            <small><?= $h($label) ?></small>
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
                    <?php foreach ($dailyRows as $row): ?>
                        <div class="activity-column">
                            <div class="bars">
                                <span
                                    class="bar users h-<?= (int) round(10 * (int) $row['users'] / $dailyMaximum) ?>"
                                ></span>
                                <span
                                    class="bar updates h-<?= (int) round(10 * (int) $row['events'] / $dailyMaximum) ?>"
                                ></span>
                            </div>
                            <small>
                                <?= $h(
                                    substr(
                                        (string) $row['day'],
                                        5
                                    )
                                ) ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="legend">
                    <span>
                        <i class="legend-users"></i>
                        کاربران یکتا
                    </span>
                    <span>
                        <i class="legend-updates"></i>
                        رویدادها
                    </span>
                </div>
            </section>

            <section class="grid two">
                <article class="panel">
                    <h2>Retention تقریبی</h2>
                    <dl class="detail-list">
                        <?php foreach (
                            [
                                'd1' => 'روز ۱',
                                'd7' => 'روز ۷',
                                'd30' => 'روز ۳۰',
                            ]
                            as $retentionKey =>
                                $retentionLabel
                        ): ?>
                            <?php
                            $retention =
                                $analytics['retention'][
                                    $retentionKey
                                ];
                            ?>
                            <div>
                                <dt>
                                    <?= $h($retentionLabel) ?>
                                </dt>
                                <dd>
                                    <?= $h($retention['rate']) ?>%
                                    <small>
                                        <?= $number($retention['retained']) ?>
                                        از
                                        <?= $number($retention['eligible']) ?>
                                    </small>
                                </dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                </article>

                <article class="panel">
                    <h2>وضعیت Job Queue</h2>
                    <dl class="detail-list">
                        <?php foreach (
                            [
                                'queued' => 'در صف',
                                'processing' => 'در حال اجرا',
                                'completed' => 'تکمیل‌شده',
                                'dead' => 'Dead',
                                'dead_letters' => 'Dead Letter فعال',
                            ]
                            as $jobKey => $jobLabel
                        ): ?>
                            <div>
                                <dt><?= $h($jobLabel) ?></dt>
                                <dd>
                                    <?= $number(
                                        $analytics['jobs'][
                                            $jobKey
                                        ]
                                    ) ?>
                                </dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                </article>
            </section>

            <section class="panel">
                <h2>فرمان‌ها و دکمه‌های پراستفاده</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ماژول</th>
                            <th>فرمان / دکمه</th>
                            <th>منبع</th>
                            <th>تعداد</th>
                            <th>میانگین زمان</th>
                            <th>موفقیت</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (
                            $analytics['commands']
                            as $row
                        ): ?>
                            <tr>
                                <td><code><?= $h($row['module']) ?></code></td>
                                <td><?= $h($row['command']) ?></td>
                                <td><?= $h($row['source']) ?></td>
                                <td><?= $number((int) $row['total']) ?></td>
                                <td><?= $h(round((float) $row['avg_duration_ms'], 2)) ?> ms</td>
                                <td><?= $h(round((float) $row['success_rate'], 2)) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <h2>عملکرد ماژول‌ها</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ماژول</th>
                            <th>رویداد</th>
                            <th>کاربر</th>
                            <th>میانگین</th>
                            <th>بیشینه</th>
                            <th>موفقیت</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (
                            $analytics['modules']
                            as $row
                        ): ?>
                            <tr>
                                <td><code><?= $h($row['module']) ?></code></td>
                                <td><?= $number((int) $row['events']) ?></td>
                                <td><?= $number((int) $row['users']) ?></td>
                                <td><?= $h(round((float) $row['avg_duration_ms'], 2)) ?> ms</td>
                                <td><?= $h(round((float) $row['max_duration_ms'], 2)) ?> ms</td>
                                <td><?= $h(round((float) $row['success_rate'], 2)) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="grid two">
                <article class="panel">
                    <h2>API Metrics</h2>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Provider</th>
                                <th>Path</th>
                                <th>Call</th>
                                <th>Latency</th>
                                <th>خطا</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (
                                $analytics['api']
                                as $row
                            ): ?>
                                <tr>
                                    <td><?= $h($row['provider']) ?></td>
                                    <td><code><?= $h($row['path']) ?></code></td>
                                    <td><?= $number((int) $row['calls']) ?></td>
                                    <td><?= $h(round((float) $row['avg_duration_ms'], 2)) ?> ms</td>
                                    <td><?= $number((int) $row['failures']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="panel">
                    <h2>Cache Metrics</h2>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Namespace</th>
                                <th>عملیات</th>
                                <th>Hit</th>
                                <th>Miss</th>
                                <th>Latency</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (
                                $analytics['cache']
                                as $row
                            ): ?>
                                <tr>
                                    <td><code><?= $h($row['namespace']) ?></code></td>
                                    <td><?= $number((int) $row['operations']) ?></td>
                                    <td><?= $number((int) $row['hits']) ?></td>
                                    <td><?= $number((int) $row['misses']) ?></td>
                                    <td><?= $h(round((float) $row['avg_duration_ms'], 3)) ?> ms</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <section class="panel">
                <h2>خطاهای پرتکرار</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ماژول</th>
                            <th>Action</th>
                            <th>کد</th>
                            <th>پیام</th>
                            <th>تعداد</th>
                            <th>آخرین</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (
                            $analytics['errors']
                            as $row
                        ): ?>
                            <tr>
                                <td><code><?= $h($row['module']) ?></code></td>
                                <td><code><?= $h($row['action']) ?></code></td>
                                <td><?= $h($row['error_code']) ?></td>
                                <td><small><?= $h($row['error_message']) ?></small></td>
                                <td><?= $number((int) $row['total']) ?></td>
                                <td><?= $h($row['last_seen_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <h2>Jobهای اخیر</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>نوع</th>
                            <th>وضعیت</th>
                            <th>تلاش</th>
                            <th>زمان اجرا</th>
                            <th>خطا</th>
                            <th>عملیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (
                            $analytics['recent_jobs']
                            as $job
                        ): ?>
                            <tr>
                                <td><code>#<?= $h($job['id']) ?></code></td>
                                <td><code><?= $h($job['job_type']) ?></code></td>
                                <td><span class="badge neutral"><?= $h($job['status']) ?></span></td>
                                <td><?= $number((int) $job['attempts']) ?> / <?= $number((int) $job['max_attempts']) ?></td>
                                <td><?= $h(date('Y-m-d H:i:s', (int) $job['available_at'])) ?></td>
                                <td><small><?= $h(mb_substr((string) ($job['last_error'] ?? ''), 0, 180)) ?></small></td>
                                <td>
                                    <?php if ($job['status'] === 'queued'): ?>
                                        <form method="post" action="<?= $h($basePath) ?>/">
                                            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                                            <input type="hidden" name="action" value="cancel_job">
                                            <input type="hidden" name="return_section" value="analytics">
                                            <input type="hidden" name="job_id" value="<?= $h($job['id']) ?>">
                                            <button class="button small danger" type="submit">لغو</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <h2>Dead Letter Queue</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Job</th>
                            <th>نوع</th>
                            <th>تلاش</th>
                            <th>خطا</th>
                            <th>زمان</th>
                            <th>عملیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (
                            $analytics['dead_letters']
                            as $dead
                        ): ?>
                            <tr>
                                <td><code>#<?= $h($dead['id']) ?></code></td>
                                <td><code>#<?= $h($dead['original_job_id']) ?></code></td>
                                <td><code><?= $h($dead['job_type']) ?></code></td>
                                <td><?= $number((int) $dead['attempts']) ?></td>
                                <td><small><?= $h(mb_substr((string) $dead['error_message'], 0, 220)) ?></small></td>
                                <td><?= $h($dead['failed_at']) ?></td>
                                <td>
                                    <?php if (($dead['replayed_at'] ?? null) === null): ?>
                                        <form method="post" action="<?= $h($basePath) ?>/">
                                            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                                            <input type="hidden" name="action" value="replay_dead_letter">
                                            <input type="hidden" name="return_section" value="analytics">
                                            <input type="hidden" name="dead_letter_id" value="<?= $h($dead['id']) ?>">
                                            <button class="button small secondary" type="submit">Replay</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge success">Replay شده</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php elseif ($section === 'features'): ?>
            <?php
            $featureFlags = $service->featureFlags();
            ?>
            <section class="panel">
                <h2>Feature Flags</h2>
                <p>
                    فعال‌سازی تدریجی قابلیت‌ها بدون Deploy.
                    Rollout برای هر کاربر به‌صورت پایدار محاسبه می‌شود.
                </p>
            </section>

            <section class="settings-grid">
                <?php foreach (
                    $featureFlags
                    as $feature
                ): ?>
                    <article class="setting-card <?= $feature['source'] === 'database' ? 'overridden' : '' ?>">
                        <div class="setting-head">
                            <div>
                                <h3><?= $h($feature['key']) ?></h3>
                                <code><?= $h($feature['source']) ?></code>
                            </div>
                            <span class="badge <?= $feature['enabled'] ? 'success' : 'danger' ?>">
                                <?= $feature['enabled'] ? 'فعال' : 'غیرفعال' ?>
                            </span>
                        </div>

                        <p><?= $h($feature['description']) ?></p>

                        <form
                            method="post"
                            action="<?= $h($basePath) ?>/"
                            class="stack"
                        >
                            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                            <input type="hidden" name="action" value="save_feature_flag">
                            <input type="hidden" name="return_section" value="features">
                            <input type="hidden" name="flag_key" value="<?= $h($feature['key']) ?>">

                            <label>
                                وضعیت
                                <select name="enabled">
                                    <option value="1" <?= $feature['enabled'] ? 'selected' : '' ?>>فعال</option>
                                    <option value="0" <?= !$feature['enabled'] ? 'selected' : '' ?>>غیرفعال</option>
                                </select>
                            </label>

                            <label>
                                Rollout درصدی
                                <input
                                    type="number"
                                    name="rollout_percentage"
                                    min="0"
                                    max="100"
                                    value="<?= $h($feature['rollout_percentage']) ?>"
                                    required
                                >
                            </label>

                            <label>
                                توضیح
                                <textarea name="description" rows="3" maxlength="500"><?= $h($feature['description']) ?></textarea>
                            </label>

                            <button class="button primary" type="submit">ذخیره</button>
                        </form>

                        <?php if ($feature['source'] === 'database'): ?>
                            <form method="post" action="<?= $h($basePath) ?>/">
                                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                                <input type="hidden" name="action" value="reset_feature_flag">
                                <input type="hidden" name="return_section" value="features">
                                <input type="hidden" name="flag_key" value="<?= $h($feature['key']) ?>">
                                <button class="button ghost" type="submit">بازگشت به Config</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
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
                                            <?= $item['type'] === 'float' ? 'step="any"' : 'step="1"' ?>
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



        <?php elseif ($section === 'mini_app'): ?>
            <?php
            $miniAppData = $service
                ->miniAppOverview(150);
            $miniSummary = $miniAppData[
                'summary'
            ];
            ?>

            <section class="metrics">
                <?php foreach (
                    [
                        ['📱', 'Session فعال', $miniSummary['active_sessions']],
                        ['👥', 'کاربران Session', $miniSummary['users_with_sessions']],
                        ['🔐', 'ورود امروز', $miniSummary['auth_today']],
                        ['⚡', 'درخواست امروز', $miniSummary['requests_today']],
                        ['🚫', 'خطای امروز', $miniSummary['failures_today']],
                        ['⏳', 'Rate Limit فعال', $miniSummary['rate_limit_keys']],
                    ]
                    as [$icon, $label, $value]
                ): ?>
                    <article class="metric-card">
                        <span class="metric-icon"><?= $h($icon) ?></span>
                        <div>
                            <small><?= $h($label) ?></small>
                            <strong><?= $number($value) ?></strong>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>امنیت و Sessionهای Mini App</h2>
                        <p>
                            Sessionهای احراز هویت‌شده، Audit و
                            پاک‌سازی داده‌های منقضی
                        </p>
                    </div>
                    <div class="actions">
                        <a
                            class="button secondary"
                            href="<?= $h((string) $runtime->get('modules.mini_app.url', 'https://alirezanobakht2004.alwaysdata.net/app/')) ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            بازکردن App
                        </a>
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
                                value="cleanup_mini_app"
                            >
                            <input
                                type="hidden"
                                name="return_section"
                                value="mini_app"
                            >
                            <button
                                class="button primary"
                                type="submit"
                            >
                                اجرای Cleanup
                            </button>
                        </form>
                    </div>
                </div>

                <div class="notice">
                    Token ربات، Session Token و CSRF Token در این صفحه
                    نمایش داده نمی‌شوند؛ دیتابیس نیز فقط Hash آن‌ها را نگه می‌دارد.
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>آخرین Sessionها</h2>
                        <p>لغو یک Session یا تمام Sessionهای یک کاربر</p>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>کاربر</th>
                            <th>ساخته‌شده</th>
                            <th>آخرین فعالیت</th>
                            <th>انقضا</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (
                            $miniAppData['sessions']
                            as $row
                        ): ?>
                            <?php
                            $sessionActive =
                                $row['revoked_at'] === null
                                && (int) $row['expires_at'] >= time()
                                && (int) $row['absolute_expires_at'] >= time();
                            $sessionName = trim(
                                (string) ($row['first_name'] ?? '')
                                . ' '
                                . (string) ($row['last_name'] ?? '')
                            );
                            ?>
                            <tr>
                                <td>#<?= $h($row['id']) ?></td>
                                <td>
                                    <?= $h($sessionName !== '' ? $sessionName : 'کاربر') ?>
                                    <small>
                                        <?= $h($row['user_id']) ?>
                                        <?= !empty($row['username']) ? ' · @' . $h($row['username']) : '' ?>
                                    </small>
                                </td>
                                <td><?= $h(date('Y-m-d H:i:s', (int) $row['created_at'])) ?></td>
                                <td><?= $h(date('Y-m-d H:i:s', (int) $row['last_seen_at'])) ?></td>
                                <td><?= $h(date('Y-m-d H:i:s', (int) min($row['expires_at'], $row['absolute_expires_at']))) ?></td>
                                <td>
                                    <span class="badge <?= $sessionActive ? 'success' : 'neutral' ?>">
                                        <?= $sessionActive ? 'فعال' : ($row['revoked_at'] !== null ? 'لغوشده' : 'منقضی') ?>
                                    </span>
                                    <?php if (!empty($row['revocation_reason'])): ?>
                                        <small><?= $h($row['revocation_reason']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <?php if ($sessionActive): ?>
                                        <form method="post" action="<?= $h($basePath) ?>/">
                                            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                                            <input type="hidden" name="action" value="revoke_mini_app_session">
                                            <input type="hidden" name="return_section" value="mini_app">
                                            <input type="hidden" name="session_id" value="<?= $h($row['id']) ?>">
                                            <button class="button small danger" type="submit">لغو Session</button>
                                        </form>
                                        <form method="post" action="<?= $h($basePath) ?>/">
                                            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                                            <input type="hidden" name="action" value="revoke_mini_app_user_sessions">
                                            <input type="hidden" name="return_section" value="mini_app">
                                            <input type="hidden" name="user_id" value="<?= $h($row['user_id']) ?>">
                                            <button class="button small danger" type="submit">لغو همه</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>Audit Mini App</h2>
                        <p>ورود، API، خطا و عملیات کاربر</p>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>زمان</th>
                            <th>کاربر</th>
                            <th>Action</th>
                            <th>Resource</th>
                            <th>نتیجه</th>
                            <th>خطا / جزئیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (
                            $miniAppData['audit']
                            as $row
                        ): ?>
                            <tr>
                                <td>#<?= $h($row['id']) ?></td>
                                <td><?= $h(date('Y-m-d H:i:s', (int) $row['occurred_at'])) ?></td>
                                <td>
                                    <?= $h(trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')) ?: 'ناشناس') ?>
                                    <small><?= $h($row['user_id'] ?? '—') ?></small>
                                </td>
                                <td><code><?= $h($row['action']) ?></code></td>
                                <td>
                                    <?= $h($row['resource_type'] ?? '—') ?>
                                    <small><?= $h($row['resource_id'] ?? '') ?></small>
                                </td>
                                <td><?= (int) $row['success'] === 1 ? '✅' : '❌' ?></td>
                                <td>
                                    <code><?= $h($row['error_code'] ?? '') ?></code>
                                    <small><?= $h(mb_substr((string) $row['details_json'], 0, 220)) ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php elseif ($section === 'quiz'): ?>
            <?php
            $quizCategoryFilter = trim(
                (string) (
                    $_GET['category'] ?? ''
                )
            );

            $quizDifficultyFilter = (
                in_array(
                    $_GET['difficulty'] ?? '',
                    ['easy', 'medium', 'hard'],
                    true
                )
            )
                ? (string) $_GET['difficulty']
                : '';

            $editQuizQuestionId = (int) (
                $_GET['edit_question'] ?? 0
            );

            $quizData = $service->quizOverview(
                editQuestionId:
                    $editQuizQuestionId,
                categorySlug:
                    $quizCategoryFilter,
                difficulty:
                    $quizDifficultyFilter,
                limit: 150
            );

            $quizSummary = $quizData['summary'];
            $quizEdit = $quizData[
                'edit_question'
            ];

            $quizOptions = [
                '',
                '',
                '',
                '',
            ];

            $quizCorrectOption = 0;

            if (is_array($quizEdit)) {
                foreach (
                    $quizEdit['options']
                    as $index => $option
                ) {
                    if ($index > 3) {
                        break;
                    }

                    $quizOptions[$index] =
                        (string) $option[
                            'option_text'
                        ];

                    if (
                        (int) $option[
                            'is_correct'
                        ] === 1
                    ) {
                        $quizCorrectOption =
                            $index;
                    }
                }
            }

            $defaultQuizDifficulty =
                is_array($quizEdit)
                    ? (string) $quizEdit[
                        'difficulty'
                    ]
                    : 'medium';

            $defaultQuizPoints =
                is_array($quizEdit)
                    ? (int) $quizEdit['points']
                    : (int) $runtime->get(
                        'modules.quiz_games.scoring.points.medium',
                        20
                    );

            $defaultQuizXp =
                is_array($quizEdit)
                    ? (int) $quizEdit[
                        'xp_reward'
                    ]
                    : (int) $runtime->get(
                        'modules.quiz_games.scoring.xp.medium',
                        18
                    );

            $defaultQuizTimeout =
                is_array($quizEdit)
                    ? (int) $quizEdit[
                        'answer_timeout_seconds'
                    ]
                    : (int) $runtime->get(
                        'modules.quiz_games.answer_timeouts.medium',
                        25
                    );

            $quizAccuracy = (float)
                $quizSummary['accuracy'];
            ?>

            <section class="metrics">
                <?php foreach (
                    [
                        ['📚', 'کل دسته‌ها', $quizSummary['categories']],
                        ['✅', 'دسته فعال', $quizSummary['categories_enabled']],
                        ['❓', 'کل سؤال‌ها', $quizSummary['questions']],
                        ['🟢', 'سؤال فعال', $quizSummary['questions_enabled']],
                        ['🎮', 'بازیکنان', $quizSummary['players']],
                        ['📝', 'کل پاسخ‌ها', $quizSummary['answers']],
                        ['🎯', 'دقت کلی', $quizAccuracy . '%'],
                        ['📅', 'چالش‌های روز', $quizSummary['daily_attempts']],
                        ['⏱', 'Session فعال', $quizSummary['active_sessions']],
                    ]
                    as [$icon, $label, $value]
                ): ?>
                    <article class="metric-card">
                        <span class="metric-icon">
                            <?= $h($icon) ?>
                        </span>
                        <div>
                            <small><?= $h($label) ?></small>
                            <strong><?= $h($value) ?></strong>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>بانک سؤال و موتور امتیاز</h2>
                        <p>
                            مدیریت سؤال، CSV، آمار سختی و
                            پاک‌سازی Sessionهای منقضی
                        </p>
                    </div>

                    <div class="actions">
                        <a
                            class="button secondary"
                            href="<?= $h($basePath) ?>/?export_quiz=questions"
                        >
                            Export CSV
                        </a>

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
                                value="process_quiz_maintenance"
                            >
                            <input
                                type="hidden"
                                name="return_section"
                                value="quiz"
                            >
                            <button
                                type="submit"
                                class="button primary"
                            >
                                اجرای Maintenance
                            </button>
                        </form>
                    </div>
                </div>

                <div class="grid two">
                    <article class="panel nested">
                        <h3>تنظیمات فعلی امتیاز</h3>
                        <p>
                            Bonus سرعت:
                            <strong>
                                <?= $h(
                                    $runtime->get(
                                        'modules.quiz_games.scoring.time_bonus_max_percent',
                                        50
                                    )
                                ) ?>%
                            </strong>
                        </p>
                        <p>
                            Bonus هر Streak:
                            <strong>
                                <?= $h(
                                    $runtime->get(
                                        'modules.quiz_games.scoring.streak_bonus_percent',
                                        5
                                    )
                                ) ?>%
                            </strong>
                        </p>
                        <p>
                            XP هر Level:
                            <strong>
                                <?= $h(
                                    $runtime->get(
                                        'modules.quiz_games.scoring.xp_per_level',
                                        100
                                    )
                                ) ?>
                            </strong>
                        </p>
                        <a
                            class="button secondary"
                            href="<?= $h($basePath) ?>/?section=settings"
                        >
                            تغییر امتیاز و زمان پاسخ
                        </a>
                    </article>

                    <article class="panel nested">
                        <h3>Import CSV</h3>
                        <p>
                            حداکثر ۲ مگابایت و ۱۰۰۰ سؤال در هر فایل.
                        </p>

                        <form
                            method="post"
                            action="<?= $h($basePath) ?>/"
                            enctype="multipart/form-data"
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
                                value="import_quiz_csv"
                            >
                            <input
                                type="hidden"
                                name="return_section"
                                value="quiz"
                            >
                            <input
                                type="file"
                                name="quiz_csv"
                                accept=".csv,text/csv"
                                required
                            >
                            <button
                                type="submit"
                                class="button primary"
                            >
                                Import
                            </button>
                        </form>
                    </article>
                </div>
            </section>

            <section class="grid two">
                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>ساخت دسته</h2>
                            <p>
                                Slug در دستورات
                                <code>/quiz science</code>
                                استفاده می‌شود.
                            </p>
                        </div>
                    </div>

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
                            value="save_quiz_category"
                        >
                        <input
                            type="hidden"
                            name="return_section"
                            value="quiz"
                        >
                        <input
                            type="hidden"
                            name="category_id"
                            value="0"
                        >

                        <label>
                            Slug
                            <input
                                type="text"
                                name="slug"
                                pattern="[a-z0-9][a-z0-9_-]{1,49}"
                                placeholder="science"
                                required
                            >
                        </label>

                        <label>
                            نام
                            <input
                                type="text"
                                name="name"
                                maxlength="150"
                                placeholder="علوم"
                                required
                            >
                        </label>

                        <label>
                            توضیح
                            <textarea
                                name="description"
                                rows="3"
                                maxlength="1000"
                            ></textarea>
                        </label>

                        <label>
                            ترتیب
                            <input
                                type="number"
                                name="sort_order"
                                value="100"
                            >
                        </label>

                        <input
                            type="hidden"
                            name="enabled"
                            value="0"
                        >
                        <label class="checkbox-row">
                            <input
                                type="checkbox"
                                name="enabled"
                                value="1"
                                checked
                            >
                            فعال
                        </label>

                        <button
                            type="submit"
                            class="button primary"
                        >
                            ذخیره دسته
                        </button>
                    </form>
                </article>

                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>دسته‌ها</h2>
                            <p>
                                غیرفعال‌کردن دسته، سؤال‌های آن را
                                از انتخاب جدید خارج می‌کند.
                            </p>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>دسته</th>
                                <th>سؤال</th>
                                <th>پاسخ</th>
                                <th>وضعیت</th>
                                <th>عملیات</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (
                                $quizData['categories']
                                as $category
                            ): ?>
                                <?php
                                $categoryAttempts =
                                    (int) $category[
                                        'correct_count'
                                    ]
                                    + (int) $category[
                                        'incorrect_count'
                                    ];

                                $categoryAccuracy =
                                    $categoryAttempts > 0
                                        ? round(
                                            (int) $category[
                                                'correct_count'
                                            ]
                                            / $categoryAttempts
                                            * 100,
                                            1
                                        )
                                        : 0;
                                ?>
                                <tr>
                                    <td>#<?= $h($category['id']) ?></td>
                                    <td>
                                        <strong>
                                            <?= $h($category['name']) ?>
                                        </strong>
                                        <small>
                                            <?= $h($category['slug']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?= $number($category['question_count']) ?>
                                    </td>
                                    <td>
                                        <?= $h($categoryAccuracy) ?>%
                                    </td>
                                    <td>
                                        <span class="badge <?= (int) $category['enabled'] === 1 ? 'success' : 'danger' ?>">
                                            <?= (int) $category['enabled'] === 1 ? 'فعال' : 'غیرفعال' ?>
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
                                                name="action"
                                                value="toggle_quiz_category"
                                            >
                                            <input
                                                type="hidden"
                                                name="return_section"
                                                value="quiz"
                                            >
                                            <input
                                                type="hidden"
                                                name="category_id"
                                                value="<?= $h($category['id']) ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="enabled"
                                                value="<?= (int) $category['enabled'] === 1 ? '0' : '1' ?>"
                                            >
                                            <button
                                                type="submit"
                                                class="button small <?= (int) $category['enabled'] === 1 ? 'danger' : 'primary' ?>"
                                            >
                                                <?= (int) $category['enabled'] === 1 ? 'غیرفعال' : 'فعال' ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>
                            <?= is_array($quizEdit) ? 'ویرایش سؤال #' . $h($quizEdit['id']) : 'ساخت سؤال جدید' ?>
                        </h2>
                        <p>
                            چهار گزینه، یک پاسخ درست، امتیاز، XP
                            و Timeout مستقل
                        </p>
                    </div>

                    <?php if (is_array($quizEdit)): ?>
                        <a
                            class="button secondary"
                            href="<?= $h($basePath) ?>/?section=quiz"
                        >
                            سؤال جدید
                        </a>
                    <?php endif; ?>
                </div>

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
                        value="save_quiz_question"
                    >
                    <input
                        type="hidden"
                        name="return_section"
                        value="quiz"
                    >
                    <input
                        type="hidden"
                        name="question_id"
                        value="<?= $h(
                            is_array($quizEdit)
                                ? $quizEdit['id']
                                : 0
                        ) ?>"
                    >

                    <div class="grid two">
                        <label>
                            دسته
                            <select
                                name="category_id"
                                required
                            >
                                <?php foreach (
                                    $quizData['categories']
                                    as $category
                                ): ?>
                                    <option
                                        value="<?= $h($category['id']) ?>"
                                        <?= is_array($quizEdit) && (int) $quizEdit['category_id'] === (int) $category['id'] ? 'selected' : '' ?>
                                    >
                                        <?= $h($category['name']) ?>
                                        (<?= $h($category['slug']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            نوع سؤال
                            <select name="question_type">
                                <option
                                    value="trivia"
                                    <?= !is_array($quizEdit) || $quizEdit['question_type'] === 'trivia' ? 'selected' : '' ?>
                                >
                                    Trivia
                                </option>
                                <option
                                    value="word"
                                    <?= is_array($quizEdit) && $quizEdit['question_type'] === 'word' ? 'selected' : '' ?>
                                >
                                    Word Game
                                </option>
                            </select>
                        </label>

                        <label>
                            سختی
                            <select name="difficulty">
                                <?php foreach (
                                    [
                                        'easy' => 'آسان',
                                        'medium' => 'متوسط',
                                        'hard' => 'سخت',
                                    ]
                                    as $value => $label
                                ): ?>
                                    <option
                                        value="<?= $h($value) ?>"
                                        <?= $defaultQuizDifficulty === $value ? 'selected' : '' ?>
                                    >
                                        <?= $h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            زمان پاسخ
                            <input
                                type="number"
                                name="answer_timeout_seconds"
                                min="5"
                                max="300"
                                value="<?= $h($defaultQuizTimeout) ?>"
                                required
                            >
                        </label>

                        <label>
                            امتیاز پایه
                            <input
                                type="number"
                                name="points"
                                min="1"
                                max="1000"
                                value="<?= $h($defaultQuizPoints) ?>"
                                required
                            >
                        </label>

                        <label>
                            XP
                            <input
                                type="number"
                                name="xp_reward"
                                min="1"
                                max="1000"
                                value="<?= $h($defaultQuizXp) ?>"
                                required
                            >
                        </label>
                    </div>

                    <label>
                        متن سؤال
                        <textarea
                            name="question_text"
                            rows="4"
                            maxlength="3000"
                            required
                        ><?= $h(
                            is_array($quizEdit)
                                ? $quizEdit['question_text']
                                : ''
                        ) ?></textarea>
                    </label>

                    <div class="grid two">
                        <?php foreach (
                            [
                                0 => 'A',
                                1 => 'B',
                                2 => 'C',
                                3 => 'D',
                            ]
                            as $index => $label
                        ): ?>
                            <label>
                                گزینه <?= $h($label) ?>
                                <input
                                    type="text"
                                    name="option_<?= mb_strtolower($label) ?>"
                                    maxlength="500"
                                    value="<?= $h($quizOptions[$index]) ?>"
                                    required
                                >
                                <span class="checkbox-row">
                                    <input
                                        type="radio"
                                        name="correct_option"
                                        value="<?= $h($index) ?>"
                                        <?= $quizCorrectOption === $index ? 'checked' : '' ?>
                                    >
                                    پاسخ درست
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <label>
                        توضیح پاسخ
                        <textarea
                            name="explanation"
                            rows="4"
                            maxlength="2000"
                        ><?= $h(
                            is_array($quizEdit)
                                ? $quizEdit['explanation']
                                : ''
                        ) ?></textarea>
                    </label>

                    <input
                        type="hidden"
                        name="enabled"
                        value="0"
                    >
                    <label class="checkbox-row">
                        <input
                            type="checkbox"
                            name="enabled"
                            value="1"
                            <?= !is_array($quizEdit) || (int) $quizEdit['enabled'] === 1 ? 'checked' : '' ?>
                        >
                        سؤال فعال باشد
                    </label>

                    <button
                        type="submit"
                        class="button primary"
                    >
                        ذخیره سؤال
                    </button>
                </form>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>بانک سؤال</h2>
                        <p>
                            آمار پاسخ صحیح، Timeout و میزان استفاده
                        </p>
                    </div>
                </div>

                <form
                    method="get"
                    action="<?= $h($basePath) ?>/"
                    class="search-form"
                >
                    <input
                        type="hidden"
                        name="section"
                        value="quiz"
                    >

                    <select name="category">
                        <option value="">همه دسته‌ها</option>
                        <?php foreach (
                            $quizData['categories']
                            as $category
                        ): ?>
                            <option
                                value="<?= $h($category['slug']) ?>"
                                <?= $quizCategoryFilter === $category['slug'] ? 'selected' : '' ?>
                            >
                                <?= $h($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="difficulty">
                        <option value="">همه سختی‌ها</option>
                        <option
                            value="easy"
                            <?= $quizDifficultyFilter === 'easy' ? 'selected' : '' ?>
                        >
                            آسان
                        </option>
                        <option
                            value="medium"
                            <?= $quizDifficultyFilter === 'medium' ? 'selected' : '' ?>
                        >
                            متوسط
                        </option>
                        <option
                            value="hard"
                            <?= $quizDifficultyFilter === 'hard' ? 'selected' : '' ?>
                        >
                            سخت
                        </option>
                    </select>

                    <button
                        type="submit"
                        class="button secondary"
                    >
                        فیلتر
                    </button>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>سؤال</th>
                            <th>دسته</th>
                            <th>سختی</th>
                            <th>استفاده</th>
                            <th>صحیح</th>
                            <th>Timeout</th>
                            <th>امتیاز / XP / زمان</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (
                            $quizData['questions']
                            as $question
                        ): ?>
                            <tr>
                                <td>#<?= $h($question['id']) ?></td>
                                <td>
                                    <?= $h(
                                        mb_substr(
                                            (string) $question[
                                                'question_text'
                                            ],
                                            0,
                                            180
                                        )
                                    ) ?>
                                </td>
                                <td>
                                    <?= $h($question['category_name']) ?>
                                    <small>
                                        <?= $h($question['question_type']) ?>
                                    </small>
                                </td>
                                <td><?= $h($question['difficulty']) ?></td>
                                <td><?= $number($question['times_served']) ?></td>
                                <td><?= $h($question['correct_percent']) ?>%</td>
                                <td><?= $number($question['timeout_count']) ?></td>
                                <td>
                                    <?= $number($question['points']) ?>
                                    /
                                    <?= $number($question['xp_reward']) ?>
                                    /
                                    <?= $number($question['answer_timeout_seconds']) ?>s
                                </td>
                                <td>
                                    <span class="badge <?= (int) $question['enabled'] === 1 ? 'success' : 'danger' ?>">
                                        <?= (int) $question['enabled'] === 1 ? 'فعال' : 'غیرفعال' ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <a
                                        class="button small secondary"
                                        href="<?= $h($basePath) ?>/?section=quiz&amp;edit_question=<?= $h($question['id']) ?>"
                                    >
                                        ویرایش
                                    </a>

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
                                            value="toggle_quiz_question"
                                        >
                                        <input
                                            type="hidden"
                                            name="return_section"
                                            value="quiz"
                                        >
                                        <input
                                            type="hidden"
                                            name="question_id"
                                            value="<?= $h($question['id']) ?>"
                                        >
                                        <input
                                            type="hidden"
                                            name="enabled"
                                            value="<?= (int) $question['enabled'] === 1 ? '0' : '1' ?>"
                                        >
                                        <button
                                            type="submit"
                                            class="button small <?= (int) $question['enabled'] === 1 ? 'danger' : 'primary' ?>"
                                        >
                                            <?= (int) $question['enabled'] === 1 ? 'غیرفعال' : 'فعال' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>سؤال‌های دشوار</h2>
                        <p>
                            سؤال‌های دارای حداقل سه تلاش، مرتب‌شده
                            بر اساس کمترین درصد پاسخ صحیح
                        </p>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>سؤال</th>
                            <th>دسته</th>
                            <th>سختی</th>
                            <th>تلاش</th>
                            <th>درست</th>
                            <th>غلط</th>
                            <th>Timeout</th>
                            <th>درصد صحیح</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (
                            $quizData['difficult']
                            as $question
                        ): ?>
                            <tr>
                                <td>#<?= $h($question['id']) ?></td>
                                <td>
                                    <?= $h(
                                        mb_substr(
                                            (string) $question[
                                                'question_text'
                                            ],
                                            0,
                                            200
                                        )
                                    ) ?>
                                </td>
                                <td><?= $h($question['category_name']) ?></td>
                                <td><?= $h($question['difficulty']) ?></td>
                                <td><?= $number($question['attempts']) ?></td>
                                <td><?= $number($question['correct_count']) ?></td>
                                <td><?= $number($question['incorrect_count']) ?></td>
                                <td><?= $number($question['timeout_count']) ?></td>
                                <td><?= $h($question['correct_percent']) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php elseif ($section === 'group_management'): ?>
            <?php
            $selectedGroupId = (int) (
                $_GET['chat_id'] ?? 0
            );

            $groupManagement =
                $service->groupManagementOverview(
                    $selectedGroupId,
                    100
                );

            $selectedGroup =
                $groupManagement[
                    'selected_group'
                ];

            $groupSettings =
                $groupManagement['settings'];

            if (is_array($selectedGroup)) {
                $selectedGroupId = (int)
                    $selectedGroup['chat_id'];
            }

            $activeWarnings = count(
                array_filter(
                    $groupManagement['warnings'],
                    static fn (array $row): bool =>
                        (int) $row['active'] === 1
                )
            );

            $activeSanctions = count(
                array_filter(
                    $groupManagement['sanctions'],
                    static fn (array $row): bool =>
                        $row['status'] === 'active'
                )
            );

            $pendingCaptchas = count(
                array_filter(
                    $groupManagement['captchas'],
                    static fn (array $row): bool =>
                        $row['status'] === 'pending'
                )
            );

            $pendingRequests = count(
                array_filter(
                    $groupManagement[
                        'join_requests'
                    ],
                    static fn (array $row): bool =>
                        $row['status'] === 'pending'
                )
            );

            $checked = static function (
                array $settings,
                string $key
            ): string {
                return (int) (
                    $settings[$key] ?? 0
                ) === 1
                    ? 'checked'
                    : '';
            };

            $memberName = static function (
                array $row,
                string $prefix = ''
            ): string {
                $first = trim(
                    (string) (
                        $row[
                            $prefix
                            . 'first_name'
                        ] ?? ''
                    )
                );

                $last = trim(
                    (string) (
                        $row[
                            $prefix
                            . 'last_name'
                        ] ?? ''
                    )
                );

                $username = trim(
                    (string) (
                        $row[
                            $prefix
                            . 'username'
                        ] ?? ''
                    )
                );

                $name = trim(
                    $first . ' ' . $last
                );

                if ($name !== '') {
                    return $name
                        . (
                            $username !== ''
                                ? ' (@'
                                    . $username
                                    . ')'
                                : ''
                        );
                }

                return $username !== ''
                    ? '@' . $username
                    : 'بدون نام';
            };
            ?>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>مدیریت حرفه‌ای گروه‌ها</h2>
                        <p>
                            تنظیم مجزای هر گروه، AutoMod، اخطار،
                            کپچا، لینک دعوت و درخواست عضویت
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
                            value="process_group_worker"
                        >
                        <input
                            type="hidden"
                            name="return_section"
                            value="group_management"
                        >
                        <input
                            type="hidden"
                            name="return_chat_id"
                            value="<?= $h($selectedGroupId) ?>"
                        >
                        <button
                            class="button primary"
                            type="submit"
                        >
                            اجرای Worker الان
                        </button>
                    </form>
                </div>

                <form
                    method="get"
                    action="<?= $h($basePath) ?>/"
                    class="search-form"
                >
                    <input
                        type="hidden"
                        name="section"
                        value="group_management"
                    >

                    <select
                        name="chat_id"
                        required
                    >
                        <?php foreach (
                            $groupManagement['groups']
                            as $group
                        ): ?>
                            <?php
                            $groupId = (int)
                                $group['chat_id'];

                            $groupTitle = trim(
                                (string) (
                                    $group['title']
                                    ?? ''
                                )
                            );

                            if ($groupTitle === '') {
                                $groupTitle =
                                    (string) $groupId;
                            }
                            ?>
                            <option
                                value="<?= $h($groupId) ?>"
                                <?= $selectedGroupId === $groupId ? 'selected' : '' ?>
                            >
                                <?= $h($groupTitle) ?>
                                · <?= $h($group['type']) ?>
                                · <?= $h($groupId) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button
                        class="button secondary"
                        type="submit"
                    >
                        بازکردن گروه
                    </button>
                </form>
            </section>

            <?php if (
                !is_array($selectedGroup)
                || !is_array($groupSettings)
            ): ?>
                <section class="panel">
                    <div class="notice">
                        هنوز گروه یا سوپرگروهی در دیتابیس ربات ثبت نشده است.
                    </div>
                </section>
            <?php else: ?>
                <section class="metrics">
                    <article class="metric-card">
                        <span class="metric-icon">⚠️</span>
                        <div>
                            <small>اخطار فعال</small>
                            <strong>
                                <?= $number($activeWarnings) ?>
                            </strong>
                        </div>
                    </article>

                    <article class="metric-card">
                        <span class="metric-icon">🔒</span>
                        <div>
                            <small>محدودیت فعال</small>
                            <strong>
                                <?= $number($activeSanctions) ?>
                            </strong>
                        </div>
                    </article>

                    <article class="metric-card">
                        <span class="metric-icon">🧩</span>
                        <div>
                            <small>کپچای در انتظار</small>
                            <strong>
                                <?= $number($pendingCaptchas) ?>
                            </strong>
                        </div>
                    </article>

                    <article class="metric-card">
                        <span class="metric-icon">🚪</span>
                        <div>
                            <small>درخواست عضویت</small>
                            <strong>
                                <?= $number($pendingRequests) ?>
                            </strong>
                        </div>
                    </article>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>
                                <?= $h(
                                    $selectedGroup['title']
                                    ?: $selectedGroupId
                                ) ?>
                            </h2>
                            <p>
                                <?= $h($selectedGroup['type']) ?>
                                · ID:
                                <?= $h($selectedGroupId) ?>
                                · آخرین فعالیت:
                                <?= $h(
                                    $selectedGroup[
                                        'last_seen_at'
                                    ] ?? '—'
                                ) ?>
                            </p>
                        </div>

                        <span
                            class="badge <?= (int) $selectedGroup['is_active'] === 1 ? 'success' : 'danger' ?>"
                        >
                            <?= (int) $selectedGroup['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
                        </span>
                    </div>

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
                            value="save_group_settings"
                        >
                        <input
                            type="hidden"
                            name="return_section"
                            value="group_management"
                        >
                        <input
                            type="hidden"
                            name="return_chat_id"
                            value="<?= $h($selectedGroupId) ?>"
                        >
                        <input
                            type="hidden"
                            name="chat_id"
                            value="<?= $h($selectedGroupId) ?>"
                        >

                        <div class="grid two">
                            <article class="panel nested">
                                <h3>اخطار و مجازات خودکار</h3>

                                <label>
                                    سقف اخطار
                                    <input
                                        type="number"
                                        name="warnings_threshold"
                                        min="1"
                                        max="20"
                                        value="<?= $h($groupSettings['warnings_threshold']) ?>"
                                        required
                                    >
                                </label>

                                <label>
                                    Action پس از سقف
                                    <select name="warning_action">
                                        <?php foreach (
                                            [
                                                'none' => 'بدون Action',
                                                'mute' => 'Mute',
                                                'ban' => 'Ban',
                                            ]
                                            as $value => $label
                                        ): ?>
                                            <option
                                                value="<?= $h($value) ?>"
                                                <?= $groupSettings['warning_action'] === $value ? 'selected' : '' ?>
                                            >
                                                <?= $h($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <label>
                                    مدت Action برحسب ثانیه
                                    <input
                                        type="number"
                                        name="warning_action_duration_seconds"
                                        min="30"
                                        max="31622400"
                                        value="<?= $h($groupSettings['warning_action_duration_seconds']) ?>"
                                        required
                                    >
                                </label>
                            </article>

                            <article class="panel nested">
                                <h3>ضداسپم و Slow Mode</h3>

                                <?php foreach (
                                    [
                                        'anti_spam_enabled' => 'ضد Flood و تکرار',
                                        'anti_link_enabled' => 'ضد لینک',
                                        'bad_words_enabled' => 'کلمات ممنوع',
                                    ]
                                    as $field => $label
                                ): ?>
                                    <input
                                        type="hidden"
                                        name="<?= $h($field) ?>"
                                        value="0"
                                    >
                                    <label class="checkbox-row">
                                        <input
                                            type="checkbox"
                                            name="<?= $h($field) ?>"
                                            value="1"
                                            <?= $checked($groupSettings, $field) ?>
                                        >
                                        <?= $h($label) ?>
                                    </label>
                                <?php endforeach; ?>

                                <label>
                                    پیام مجاز در پنجره Flood
                                    <input
                                        type="number"
                                        name="flood_max_messages"
                                        min="2"
                                        max="100"
                                        value="<?= $h($groupSettings['flood_max_messages']) ?>"
                                        required
                                    >
                                </label>

                                <label>
                                    پنجره Flood برحسب ثانیه
                                    <input
                                        type="number"
                                        name="flood_window_seconds"
                                        min="1"
                                        max="300"
                                        value="<?= $h($groupSettings['flood_window_seconds']) ?>"
                                        required
                                    >
                                </label>

                                <label>
                                    تکرار مجاز یک پیام
                                    <input
                                        type="number"
                                        name="duplicate_max_messages"
                                        min="1"
                                        max="20"
                                        value="<?= $h($groupSettings['duplicate_max_messages']) ?>"
                                        required
                                    >
                                </label>

                                <label>
                                    پنجره تکرار برحسب ثانیه
                                    <input
                                        type="number"
                                        name="duplicate_window_seconds"
                                        min="1"
                                        max="600"
                                        value="<?= $h($groupSettings['duplicate_window_seconds']) ?>"
                                        required
                                    >
                                </label>

                                <label>
                                    Slow Mode ربات برحسب ثانیه
                                    <input
                                        type="number"
                                        name="bot_slow_mode_seconds"
                                        min="0"
                                        max="3600"
                                        value="<?= $h($groupSettings['bot_slow_mode_seconds']) ?>"
                                        required
                                    >
                                </label>
                            </article>

                            <article class="panel nested">
                                <h3>کپچا و درخواست عضویت</h3>

                                <input
                                    type="hidden"
                                    name="captcha_enabled"
                                    value="0"
                                >
                                <label class="checkbox-row">
                                    <input
                                        type="checkbox"
                                        name="captcha_enabled"
                                        value="1"
                                        <?= $checked($groupSettings, 'captcha_enabled') ?>
                                    >
                                    کپچای اعضای جدید
                                </label>

                                <label>
                                    مهلت کپچا برحسب ثانیه
                                    <input
                                        type="number"
                                        name="captcha_timeout_seconds"
                                        min="30"
                                        max="1800"
                                        value="<?= $h($groupSettings['captcha_timeout_seconds']) ?>"
                                        required
                                    >
                                </label>

                                <label>
                                    حداکثر تلاش کپچا
                                    <input
                                        type="number"
                                        name="captcha_max_attempts"
                                        min="1"
                                        max="10"
                                        value="<?= $h($groupSettings['captcha_max_attempts']) ?>"
                                        required
                                    >
                                </label>

                                <label>
                                    Action کپچای ناموفق
                                    <select name="captcha_failure_action">
                                        <option
                                            value="kick"
                                            <?= $groupSettings['captcha_failure_action'] === 'kick' ? 'selected' : '' ?>
                                        >
                                            Kick
                                        </option>
                                        <option
                                            value="ban"
                                            <?= $groupSettings['captcha_failure_action'] === 'ban' ? 'selected' : '' ?>
                                        >
                                            Ban
                                        </option>
                                    </select>
                                </label>

                                <label>
                                    مدیریت درخواست عضویت
                                    <select name="join_request_mode">
                                        <?php foreach (
                                            [
                                                'manual' => 'دستی',
                                                'approve' => 'تأیید خودکار',
                                                'decline' => 'رد خودکار',
                                            ]
                                            as $value => $label
                                        ): ?>
                                            <option
                                                value="<?= $h($value) ?>"
                                                <?= $groupSettings['join_request_mode'] === $value ? 'selected' : '' ?>
                                            >
                                                <?= $h($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </article>

                            <article class="panel nested">
                                <h3>Welcome، Goodbye و قوانین</h3>

                                <input
                                    type="hidden"
                                    name="welcome_enabled"
                                    value="0"
                                >
                                <label class="checkbox-row">
                                    <input
                                        type="checkbox"
                                        name="welcome_enabled"
                                        value="1"
                                        <?= $checked($groupSettings, 'welcome_enabled') ?>
                                    >
                                    پیام خوش‌آمدگویی
                                </label>

                                <textarea
                                    name="welcome_message"
                                    rows="4"
                                    maxlength="4000"
                                    placeholder="خوش آمدی {first_name} به {chat_title}"
                                ><?= $h($groupSettings['welcome_message'] ?? '') ?></textarea>

                                <input
                                    type="hidden"
                                    name="goodbye_enabled"
                                    value="0"
                                >
                                <label class="checkbox-row">
                                    <input
                                        type="checkbox"
                                        name="goodbye_enabled"
                                        value="1"
                                        <?= $checked($groupSettings, 'goodbye_enabled') ?>
                                    >
                                    پیام خداحافظی
                                </label>

                                <textarea
                                    name="goodbye_message"
                                    rows="4"
                                    maxlength="4000"
                                    placeholder="{first_name} از گروه خارج شد."
                                ><?= $h($groupSettings['goodbye_message'] ?? '') ?></textarea>

                                <label>
                                    قوانین گروه
                                    <textarea
                                        name="rules_text"
                                        rows="7"
                                        maxlength="4000"
                                    ><?= $h($groupSettings['rules_text'] ?? '') ?></textarea>
                                </label>

                                <small>
                                    متغیرها:
                                    {first_name}، {last_name}،
                                    {full_name}، {username}،
                                    {user_id}، {chat_title}
                                </small>
                            </article>
                        </div>

                        <button
                            class="button primary"
                            type="submit"
                        >
                            ذخیره تمام تنظیمات گروه
                        </button>
                    </form>
                </section>

                <section class="grid two">
                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h2>Whitelist دامنه</h2>
                                <p>
                                    زیر دامنه‌های هر Domain نیز مجاز هستند.
                                </p>
                            </div>
                        </div>

                        <form
                            method="post"
                            action="<?= $h($basePath) ?>/"
                            class="search-form"
                        >
                            <input
                                type="hidden"
                                name="csrf"
                                value="<?= $h($csrf) ?>"
                            >
                            <input
                                type="hidden"
                                name="action"
                                value="update_group_list"
                            >
                            <input
                                type="hidden"
                                name="return_section"
                                value="group_management"
                            >
                            <input
                                type="hidden"
                                name="return_chat_id"
                                value="<?= $h($selectedGroupId) ?>"
                            >
                            <input
                                type="hidden"
                                name="chat_id"
                                value="<?= $h($selectedGroupId) ?>"
                            >
                            <input
                                type="hidden"
                                name="list_type"
                                value="domain"
                            >
                            <input
                                type="hidden"
                                name="operation"
                                value="add"
                            >
                            <input
                                type="text"
                                name="value"
                                placeholder="example.com"
                                required
                            >
                            <button
                                class="button primary"
                                type="submit"
                            >
                                افزودن
                            </button>
                        </form>

                        <div class="tag-list">
                            <?php foreach (
                                $groupManagement['domains']
                                as $domain
                            ): ?>
                                <form
                                    method="post"
                                    action="<?= $h($basePath) ?>/"
                                    class="inline-form"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf"
                                        value="<?= $h($csrf) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="update_group_list"
                                    >
                                    <input
                                        type="hidden"
                                        name="return_section"
                                        value="group_management"
                                    >
                                    <input
                                        type="hidden"
                                        name="return_chat_id"
                                        value="<?= $h($selectedGroupId) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="chat_id"
                                        value="<?= $h($selectedGroupId) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="list_type"
                                        value="domain"
                                    >
                                    <input
                                        type="hidden"
                                        name="operation"
                                        value="remove"
                                    >
                                    <input
                                        type="hidden"
                                        name="value"
                                        value="<?= $h($domain) ?>"
                                    >
                                    <button
                                        class="button small danger"
                                        type="submit"
                                    >
                                        <?= $h($domain) ?> ×
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </article>

                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h2>کلمات ممنوع</h2>
                                <p>
                                    عبارت‌های ثبت‌شده پس از Normalization بررسی می‌شوند.
                                </p>
                            </div>
                        </div>

                        <form
                            method="post"
                            action="<?= $h($basePath) ?>/"
                            class="search-form"
                        >
                            <input
                                type="hidden"
                                name="csrf"
                                value="<?= $h($csrf) ?>"
                            >
                            <input
                                type="hidden"
                                name="action"
                                value="update_group_list"
                            >
                            <input
                                type="hidden"
                                name="return_section"
                                value="group_management"
                            >
                            <input
                                type="hidden"
                                name="return_chat_id"
                                value="<?= $h($selectedGroupId) ?>"
                            >
                            <input
                                type="hidden"
                                name="chat_id"
                                value="<?= $h($selectedGroupId) ?>"
                            >
                            <input
                                type="hidden"
                                name="list_type"
                                value="bad_word"
                            >
                            <input
                                type="hidden"
                                name="operation"
                                value="add"
                            >
                            <input
                                type="text"
                                name="value"
                                placeholder="کلمه یا عبارت"
                                required
                            >
                            <button
                                class="button primary"
                                type="submit"
                            >
                                افزودن
                            </button>
                        </form>

                        <div class="tag-list">
                            <?php foreach (
                                $groupManagement['bad_words']
                                as $word
                            ): ?>
                                <form
                                    method="post"
                                    action="<?= $h($basePath) ?>/"
                                    class="inline-form"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf"
                                        value="<?= $h($csrf) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="action"
                                        value="update_group_list"
                                    >
                                    <input
                                        type="hidden"
                                        name="return_section"
                                        value="group_management"
                                    >
                                    <input
                                        type="hidden"
                                        name="return_chat_id"
                                        value="<?= $h($selectedGroupId) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="chat_id"
                                        value="<?= $h($selectedGroupId) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="list_type"
                                        value="bad_word"
                                    >
                                    <input
                                        type="hidden"
                                        name="operation"
                                        value="remove"
                                    >
                                    <input
                                        type="hidden"
                                        name="value"
                                        value="<?= $h($word) ?>"
                                    >
                                    <button
                                        class="button small danger"
                                        type="submit"
                                    >
                                        <?= $h($word) ?> ×
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </article>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>درخواست‌های عضویت</h2>
                            <p>
                                تصمیم‌های دستی و تاریخچه درخواست‌ها
                            </p>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>کاربر</th>
                                <th>Bio</th>
                                <th>وضعیت</th>
                                <th>زمان</th>
                                <th>عملیات</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (
                                $groupManagement[
                                    'join_requests'
                                ]
                                as $row
                            ): ?>
                                <tr>
                                    <td>#<?= $h($row['id']) ?></td>
                                    <td>
                                        <?= $h($memberName($row)) ?>
                                        <small>
                                            <?= $h($row['user_id']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?= $h(
                                            mb_substr(
                                                (string) (
                                                    $row['bio'] ?? ''
                                                ),
                                                0,
                                                180
                                            )
                                        ) ?>
                                    </td>
                                    <td>
                                        <span class="badge neutral">
                                            <?= $h($row['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= $h($row['requested_at']) ?>
                                    </td>
                                    <td class="actions">
                                        <?php if (
                                            $row['status']
                                            === 'pending'
                                        ): ?>
                                            <?php foreach (
                                                [
                                                    'approve' => 'تأیید',
                                                    'decline' => 'رد',
                                                ]
                                                as $decision => $label
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
                                                        value="resolve_group_join"
                                                    >
                                                    <input
                                                        type="hidden"
                                                        name="return_section"
                                                        value="group_management"
                                                    >
                                                    <input
                                                        type="hidden"
                                                        name="return_chat_id"
                                                        value="<?= $h($selectedGroupId) ?>"
                                                    >
                                                    <input
                                                        type="hidden"
                                                        name="chat_id"
                                                        value="<?= $h($selectedGroupId) ?>"
                                                    >
                                                    <input
                                                        type="hidden"
                                                        name="request_id"
                                                        value="<?= $h($row['id']) ?>"
                                                    >
                                                    <input
                                                        type="hidden"
                                                        name="decision"
                                                        value="<?= $h($decision) ?>"
                                                    >
                                                    <button
                                                        class="button small <?= $decision === 'approve' ? 'primary' : 'danger' ?>"
                                                        type="submit"
                                                    >
                                                        <?= $h($label) ?>
                                                    </button>
                                                </form>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>اخطارها</h2>
                            <p>آخرین اخطارهای ثبت‌شده گروه</p>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>کاربر</th>
                                <th>مدیر</th>
                                <th>دلیل</th>
                                <th>وضعیت</th>
                                <th>زمان</th>
                                <th>عملیات</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (
                                $groupManagement['warnings']
                                as $row
                            ): ?>
                                <tr>
                                    <td>#<?= $h($row['id']) ?></td>
                                    <td>
                                        <?= $h(
                                            $memberName(
                                                $row,
                                                'target_'
                                            )
                                        ) ?>
                                        <small>
                                            <?= $h($row['user_id']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?= $h(
                                            $memberName(
                                                $row,
                                                'admin_'
                                            )
                                        ) ?>
                                    </td>
                                    <td>
                                        <?= $h(
                                            mb_substr(
                                                (string) (
                                                    $row['reason'] ?? ''
                                                ),
                                                0,
                                                220
                                            )
                                        ) ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= (int) $row['active'] === 1 ? 'danger' : 'neutral' ?>">
                                            <?= (int) $row['active'] === 1 ? 'فعال' : 'لغوشده' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= $h($row['created_at']) ?>
                                    </td>
                                    <td>
                                        <?php if (
                                            (int) $row['active']
                                            === 1
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
                                                    value="clear_group_warnings"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="return_section"
                                                    value="group_management"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="return_chat_id"
                                                    value="<?= $h($selectedGroupId) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="chat_id"
                                                    value="<?= $h($selectedGroupId) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="target_user_id"
                                                    value="<?= $h($row['user_id']) ?>"
                                                >
                                                <button
                                                    class="button small danger"
                                                    type="submit"
                                                >
                                                    پاک‌کردن اخطارهای کاربر
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

                <section class="grid two">
                    <article class="panel">
                        <h2>محدودیت‌ها</h2>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>کاربر</th>
                                    <th>نوع</th>
                                    <th>وضعیت</th>
                                    <th>پایان</th>
                                    <th>خطا</th>
                                    <th>عملیات</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach (
                                    $groupManagement[
                                        'sanctions'
                                    ]
                                    as $row
                                ): ?>
                                    <tr>
                                        <td>#<?= $h($row['id']) ?></td>
                                        <td>
                                            <?= $h($memberName($row)) ?>
                                            <small>
                                                <?= $h($row['user_id']) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?= $h($row['sanction_type']) ?>
                                        </td>
                                        <td>
                                            <?= $h($row['status']) ?>
                                        </td>
                                        <td>
                                            <?= $h($row['until_at'] ?? 'دائم') ?>
                                        </td>
                                        <td>
                                            <?= $h(
                                                mb_substr(
                                                    (string) (
                                                        $row['last_error']
                                                        ?? ''
                                                    ),
                                                    0,
                                                    160
                                                )
                                            ) ?>
                                        </td>
                                        <td>
                                            <?php if (
                                                $row['status']
                                                === 'active'
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
                                                        value="lift_group_sanction"
                                                    >
                                                    <input
                                                        type="hidden"
                                                        name="return_section"
                                                        value="group_management"
                                                    >
                                                    <input
                                                        type="hidden"
                                                        name="return_chat_id"
                                                        value="<?= $h($selectedGroupId) ?>"
                                                    >
                                                    <input
                                                        type="hidden"
                                                        name="chat_id"
                                                        value="<?= $h($selectedGroupId) ?>"
                                                    >
                                                    <input
                                                        type="hidden"
                                                        name="sanction_id"
                                                        value="<?= $h($row['id']) ?>"
                                                    >
                                                    <button
                                                        class="button small secondary"
                                                        type="submit"
                                                    >
                                                        رفع محدودیت
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>

                    <article class="panel">
                        <h2>کپچاها</h2>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>کاربر</th>
                                    <th>وضعیت</th>
                                    <th>تلاش</th>
                                    <th>انقضا</th>
                                    <th>عملیات</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach (
                                    $groupManagement[
                                        'captchas'
                                    ]
                                    as $row
                                ): ?>
                                    <tr>
                                        <td>#<?= $h($row['id']) ?></td>
                                        <td>
                                            <?= $h($memberName($row)) ?>
                                            <small>
                                                <?= $h($row['user_id']) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?= $h($row['status']) ?>
                                        </td>
                                        <td>
                                            <?= $number($row['attempts']) ?>
                                            /
                                            <?= $number($row['max_attempts']) ?>
                                        </td>
                                        <td>
                                            <?= $h(
                                                date(
                                                    'Y-m-d H:i:s',
                                                    (int) $row[
                                                        'expires_at'
                                                    ]
                                                )
                                            ) ?>
                                        </td>
                                        <td>
                                            <?php if (
                                                $row['status']
                                                === 'pending'
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
                                                        value="cancel_group_captcha"
                                                    >
                                                    <input
                                                        type="hidden"
                                                        name="return_section"
                                                        value="group_management"
                                                    >
                                                    <input
                                                        type="hidden"
                                                        name="return_chat_id"
                                                        value="<?= $h($selectedGroupId) ?>"
                                                    >
                                                    <input
                                                        type="hidden"
                                                        name="chat_id"
                                                        value="<?= $h($selectedGroupId) ?>"
                                                    >
                                                    <input
                                                        type="hidden"
                                                        name="captcha_id"
                                                        value="<?= $h($row['id']) ?>"
                                                    >
                                                    <button
                                                        class="button small danger"
                                                        type="submit"
                                                    >
                                                        لغو کپچا
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>ساخت لینک دعوت</h2>
                            <p>
                                لینک محدود، تاریخ‌دار یا مبتنی بر Join Request
                            </p>
                        </div>
                    </div>

                    <form
                        method="post"
                        action="<?= $h($basePath) ?>/"
                        class="search-form"
                    >
                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= $h($csrf) ?>"
                        >
                        <input
                            type="hidden"
                            name="action"
                            value="create_group_invite"
                        >
                        <input
                            type="hidden"
                            name="return_section"
                            value="group_management"
                        >
                        <input
                            type="hidden"
                            name="return_chat_id"
                            value="<?= $h($selectedGroupId) ?>"
                        >
                        <input
                            type="hidden"
                            name="chat_id"
                            value="<?= $h($selectedGroupId) ?>"
                        >

                        <input
                            type="text"
                            name="link_name"
                            maxlength="32"
                            placeholder="نام اختیاری"
                        >

                        <input
                            type="number"
                            name="expire_minutes"
                            min="0"
                            value="1440"
                            placeholder="اعتبار دقیقه"
                        >

                        <input
                            type="number"
                            name="member_limit"
                            min="0"
                            max="99999"
                            value="0"
                            placeholder="سقف عضو"
                        >

                        <label class="checkbox-row">
                            <input
                                type="checkbox"
                                name="creates_join_request"
                                value="1"
                            >
                            درخواست عضویت
                        </label>

                        <button
                            class="button primary"
                            type="submit"
                        >
                            ساخت لینک
                        </button>
                    </form>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>لینک‌های دعوت ساخته‌شده توسط ربات</h2>
                            <p>
                                لینک‌های محدود، تاریخ‌دار و Join Request
                            </p>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>نام</th>
                                <th>لینک</th>
                                <th>انقضا</th>
                                <th>سقف عضو</th>
                                <th>Request</th>
                                <th>وضعیت</th>
                                <th>عملیات</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (
                                $groupManagement[
                                    'invite_links'
                                ]
                                as $row
                            ): ?>
                                <tr>
                                    <td>#<?= $h($row['id']) ?></td>
                                    <td><?= $h($row['link_name'] ?? '') ?></td>
                                    <td>
                                        <code>
                                            <?= $h(
                                                mb_substr(
                                                    (string) $row[
                                                        'invite_link'
                                                    ],
                                                    0,
                                                    100
                                                )
                                            ) ?>
                                        </code>
                                    </td>
                                    <td>
                                        <?= $h(
                                            $row['expire_at']
                                            ?? '—'
                                        ) ?>
                                    </td>
                                    <td>
                                        <?= $h(
                                            $row['member_limit']
                                            ?? '—'
                                        ) ?>
                                    </td>
                                    <td>
                                        <?= (int) $row['creates_join_request'] === 1 ? 'بله' : 'خیر' ?>
                                    </td>
                                    <td><?= $h($row['status']) ?></td>
                                    <td>
                                        <?php if (
                                            $row['status']
                                            === 'active'
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
                                                    value="revoke_group_invite"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="return_section"
                                                    value="group_management"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="return_chat_id"
                                                    value="<?= $h($selectedGroupId) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="chat_id"
                                                    value="<?= $h($selectedGroupId) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="invite_id"
                                                    value="<?= $h($row['id']) ?>"
                                                >
                                                <button
                                                    class="button small danger"
                                                    type="submit"
                                                >
                                                    لغو
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

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Audit گروه</h2>
                            <p>
                                عملیات مدیران، AutoMod، ورود و خروج،
                                کپچا و درخواست عضویت
                            </p>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Action</th>
                                <th>Actor</th>
                                <th>Target</th>
                                <th>موفق</th>
                                <th>جزئیات</th>
                                <th>زمان</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (
                                $groupManagement['audit']
                                as $row
                            ): ?>
                                <tr>
                                    <td>#<?= $h($row['id']) ?></td>
                                    <td>
                                        <code>
                                            <?= $h($row['action']) ?>
                                        </code>
                                    </td>
                                    <td>
                                        <?= $h(
                                            $row[
                                                'actor_first_name'
                                            ] ?? 'سیستم'
                                        ) ?>
                                        <small>
                                            <?= $h(
                                                $row['actor_id']
                                                ?? ''
                                            ) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?= $h(
                                            $row[
                                                'target_first_name'
                                            ] ?? ''
                                        ) ?>
                                        <small>
                                            <?= $h(
                                                $row[
                                                    'target_user_id'
                                                ] ?? ''
                                            ) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?= (int) $row['success'] === 1 ? '✅' : '❌' ?>
                                    </td>
                                    <td>
                                        <small>
                                            <?= $h(
                                                mb_substr(
                                                    (string) (
                                                        $row[
                                                            'error_message'
                                                        ]
                                                        ?? $row[
                                                            'details_json'
                                                        ]
                                                    ),
                                                    0,
                                                    260
                                                )
                                            ) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?= $h($row['created_at']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

        <?php elseif ($section === 'automation'): ?>
            <?php
            $automation = $service->automationOverview(150);
            $selectedMonitorId = max(
                0,
                (int) ($_GET['monitor_id'] ?? 0)
            );
            $monitorChart = $selectedMonitorId > 0
                ? $service->monitorDailyUptime(
                    $selectedMonitorId,
                    30
                )
                : [];
            $monitorChecks = $selectedMonitorId > 0
                ? $service->monitorChecks(
                    $selectedMonitorId,
                    50
                )
                : [];
            ?>

            <section class="metrics">
                <?php foreach ([
                    ['🔔', 'هشدار فعال', $stats['alerts_active']],
                    ['📬', 'اشتراک فعال', $stats['subscriptions_active']],
                    ['📡', 'مانیتور فعال', $stats['monitors_active']],
                    ['❌', 'مانیتور Down', $stats['monitors_down']],
                ] as [$icon, $label, $value]): ?>
                    <article class="metric-card">
                        <span class="metric-icon"><?= $h($icon) ?></span>
                        <div>
                            <small><?= $h($label) ?></small>
                            <strong><?= $number($value) ?></strong>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>هشدارهای هوشمند</h2>
                        <p>آب‌وهوا، دما، باد و نرخ ارز</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr>
                            <th>ID</th><th>کاربر</th><th>نوع</th><th>موضوع</th>
                            <th>شرط</th><th>آخرین مقدار</th><th>وضعیت</th><th>عملیات</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($automation['alerts'] as $row): ?>
                            <tr>
                                <td>#<?= $h($row['id']) ?></td>
                                <td>
                                    <?= $h(trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''))) ?>
                                    <small><?= $h($row['user_id']) ?></small>
                                </td>
                                <td><?= $h($row['alert_type']) ?></td>
                                <td>
                                    <?= $h($row['subject']) ?>
                                    <?php if (!empty($row['secondary_subject'])): ?>
                                        /<?= $h($row['secondary_subject']) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $h($row['operator']) ?>
                                    <?= $h($row['threshold_value'] ?? $row['comparison_value'] ?? '') ?>
                                </td>
                                <td><?= $h($row['last_observed_value'] ?? '—') ?></td>
                                <td><span class="badge neutral"><?= $h($row['status']) ?></span></td>
                                <td class="actions">
                                    <?php foreach (['active' => 'فعال', 'paused' => 'توقف', 'cancelled' => 'لغو'] as $statusKey => $statusLabel): ?>
                                        <?php if ($row['status'] !== $statusKey): ?>
                                            <form method="post" action="<?= $h($basePath) ?>/">
                                                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                                                <input type="hidden" name="action" value="automation_status">
                                                <input type="hidden" name="return_section" value="automation">
                                                <input type="hidden" name="automation_type" value="alert">
                                                <input type="hidden" name="automation_id" value="<?= $h($row['id']) ?>">
                                                <input type="hidden" name="automation_status" value="<?= $h($statusKey) ?>">
                                                <button class="button small secondary" type="submit"><?= $h($statusLabel) ?></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>اشتراک‌های زمان‌بندی‌شده</h2>
                        <p>گزارش‌های روزانه، هفتگی و ماهانه</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr>
                            <th>ID</th><th>کاربر</th><th>نوع</th><th>موضوع</th>
                            <th>زمان‌بندی</th><th>اجرای بعدی</th><th>وضعیت</th><th>عملیات</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($automation['subscriptions'] as $row): ?>
                            <tr>
                                <td>#<?= $h($row['id']) ?></td>
                                <td><?= $h($row['first_name'] ?? '') ?><small><?= $h($row['user_id']) ?></small></td>
                                <td><?= $h($row['subscription_type']) ?></td>
                                <td><?= $h($row['subject']) ?></td>
                                <td>
                                    <?= $h($row['frequency']) ?>
                                    <?= $h($row['schedule_time']) ?>
                                    <small><?= $h($row['timezone']) ?></small>
                                </td>
                                <td><?= $h(date('Y-m-d H:i', (int) $row['next_run_at'])) ?></td>
                                <td><span class="badge neutral"><?= $h($row['status']) ?></span></td>
                                <td class="actions">
                                    <?php foreach (['active' => 'فعال', 'paused' => 'توقف', 'cancelled' => 'لغو'] as $statusKey => $statusLabel): ?>
                                        <?php if ($row['status'] !== $statusKey): ?>
                                            <form method="post" action="<?= $h($basePath) ?>/">
                                                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                                                <input type="hidden" name="action" value="automation_status">
                                                <input type="hidden" name="return_section" value="automation">
                                                <input type="hidden" name="automation_type" value="subscription">
                                                <input type="hidden" name="automation_id" value="<?= $h($row['id']) ?>">
                                                <input type="hidden" name="automation_status" value="<?= $h($statusKey) ?>">
                                                <button class="button small secondary" type="submit"><?= $h($statusLabel) ?></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>مانیتورهای سایت</h2>
                        <p>وضعیت، زمان پاسخ، خطا و نمودار Availability</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr>
                            <th>ID</th><th>کاربر</th><th>URL</th><th>فاصله</th>
                            <th>وضعیت فعلی</th><th>HTTP / زمان</th><th>Check</th><th>عملیات</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($automation['monitors'] as $row): ?>
                            <tr>
                                <td>#<?= $h($row['id']) ?></td>
                                <td><?= $h($row['first_name'] ?? '') ?><small><?= $h($row['user_id']) ?></small></td>
                                <td><code><?= $h($row['url']) ?></code></td>
                                <td><?= $number((int) $row['interval_seconds']) ?>s</td>
                                <td>
                                    <span class="badge <?= $row['last_state'] === 'down' ? 'danger' : 'neutral' ?>">
                                        <?= $h($row['last_state']) ?> / <?= $h($row['status']) ?>
                                    </span>
                                    <?php if (!empty($row['last_error'])): ?>
                                        <small><?= $h(mb_substr((string) $row['last_error'], 0, 150)) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= $h($row['last_status_code'] ?? '—') ?> / <?= $h($row['last_response_ms'] ?? '—') ?> ms</td>
                                <td><?= $number((int) $row['checks_count']) ?></td>
                                <td class="actions">
                                    <a class="button small primary" href="<?= $h($basePath) ?>/?section=automation&amp;monitor_id=<?= (int) $row['id'] ?>">نمودار</a>
                                    <?php foreach (['active' => 'فعال', 'paused' => 'توقف', 'cancelled' => 'لغو'] as $statusKey => $statusLabel): ?>
                                        <?php if ($row['status'] !== $statusKey): ?>
                                            <form method="post" action="<?= $h($basePath) ?>/">
                                                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                                                <input type="hidden" name="action" value="automation_status">
                                                <input type="hidden" name="return_section" value="automation">
                                                <input type="hidden" name="automation_type" value="monitor">
                                                <input type="hidden" name="automation_id" value="<?= $h($row['id']) ?>">
                                                <input type="hidden" name="automation_status" value="<?= $h($statusKey) ?>">
                                                <button class="button small secondary" type="submit"><?= $h($statusLabel) ?></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php if ($selectedMonitorId > 0): ?>
                <section class="panel">
                    <h2>Availability روزانه مانیتور #<?= $selectedMonitorId ?></h2>
                    <?php if ($monitorChart === []): ?>
                        <div class="notice">هنوز داده‌ای ثبت نشده است.</div>
                    <?php else: ?>
                        <div class="activity-chart">
                            <?php foreach ($monitorChart as $row): ?>
                                <div class="activity-column">
                                    <div class="bars">
                                        <span class="bar updates h-<?= max(0, min(10, (int) round($row['uptime'] / 10))) ?>"></span>
                                    </div>
                                    <small><?= $h(substr($row['date'], 5)) ?></small>
                                    <small><?= $h($row['uptime']) ?>%</small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>زمان</th><th>State</th><th>HTTP</th><th>Response</th><th>IP</th><th>خطا</th></tr></thead>
                            <tbody>
                            <?php foreach ($monitorChecks as $check): ?>
                                <tr>
                                    <td><?= $h(date('Y-m-d H:i:s', (int) $check['checked_at'])) ?></td>
                                    <td><?= $h($check['state']) ?></td>
                                    <td><?= $h($check['status_code'] ?? '—') ?></td>
                                    <td><?= $h($check['response_ms'] ?? '—') ?> ms</td>
                                    <td><?= $h($check['primary_ip'] ?? '—') ?></td>
                                    <td><?= $h(mb_substr((string) ($check['error_message'] ?? ''), 0, 180)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

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
