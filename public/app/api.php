<?php

declare(strict_types=1);

use SmartToolbox\Core\Database;
use SmartToolbox\Core\FeatureRegistry;
use SmartToolbox\Core\RuntimeSettings;
use SmartToolbox\Core\SsrfGuard;
use SmartToolbox\Modules\Alerts\ScheduleCalculator;
use SmartToolbox\Modules\MiniApp\InitDataValidator;
use SmartToolbox\Modules\MiniApp\MiniAppApiController;
use SmartToolbox\Modules\MiniApp\MiniAppAuditLogger;
use SmartToolbox\Modules\MiniApp\MiniAppException;
use SmartToolbox\Modules\MiniApp\MiniAppRateLimiter;
use SmartToolbox\Modules\MiniApp\MiniAppRepository;
use SmartToolbox\Modules\MiniApp\MiniAppSessionRepository;
use SmartToolbox\Modules\Monitoring\MonitorProbe;

$rootPath = dirname(__DIR__, 2);
$method = mb_strtoupper(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
);
$action = trim(
    (string) ($_GET['action'] ?? '')
);
$ipAddress = (string) (
    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
);
$userAgent = (string) (
    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");

$pdo = null;
$audit = null;
$session = null;
$sessionToken = null;
$userId = null;
$cookieName = '__Secure-smarttoolbox-mini';

/**
 * @param array<string,mixed> $payload
 */
$respond = static function (
    array $payload,
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
    ) . PHP_EOL;

    exit;
};

$clearCookie = static function (
    string $name
): void {
    setcookie(
        $name,
        '',
        [
            'expires' => 1,
            'path' => '/app',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]
    );
};

try {
    if (!in_array($method, ['GET', 'POST'], true)) {
        throw new MiniAppException(
            'متد HTTP پشتیبانی نمی‌شود.',
            'method_not_allowed',
            405
        );
    }

    if ($action === '') {
        throw new MiniAppException(
            'عملیات API مشخص نشده است.',
            'action_missing',
            400
        );
    }

    $config = require $rootPath
        . '/bootstrap/app.php';

    $pdo = Database::connect(
        (string) $config->get('database.path')
    );

    $runtime = new RuntimeSettings(
        $config,
        $pdo
    );

    $features = new FeatureRegistry(
        $pdo,
        (array) $config->get(
            'features.defaults',
            []
        )
    );

    if (
        !(bool) $runtime->get(
            'modules.mini_app.enabled',
            true
        )
        || !$features->isEnabled('mini_app')
    ) {
        throw new MiniAppException(
            'Mini App موقتاً غیرفعال است.',
            'mini_app_disabled',
            503
        );
    }

    $cookieName = (string) $runtime->get(
        'modules.mini_app.security.cookie_name',
        '__Secure-smarttoolbox-mini'
    );

    if (
        preg_match(
            '/^[A-Za-z0-9_-]{8,80}$/',
            $cookieName
        ) !== 1
    ) {
        throw new MiniAppException(
            'نام Cookie Mini App معتبر نیست.',
            'cookie_name_invalid',
            500
        );
    }

    $sessions = new MiniAppSessionRepository(
        pdo: $pdo,
        idleTtlSeconds: (int) $runtime->get(
            'modules.mini_app.security.session_idle_ttl_seconds',
            1200
        ),
        absoluteTtlSeconds: (int) $runtime->get(
            'modules.mini_app.security.session_absolute_ttl_seconds',
            21600
        ),
        maxActivePerUser: (int) $runtime->get(
            'modules.mini_app.security.max_active_sessions_per_user',
            5
        )
    );

    $limiter = new MiniAppRateLimiter($pdo);
    $audit = new MiniAppAuditLogger($pdo);
    $repository = new MiniAppRepository($pdo);

    $body = [];

    if ($method === 'POST') {
        $contentType = mb_strtolower(
            trim(
                explode(
                    ';',
                    (string) (
                        $_SERVER['CONTENT_TYPE']
                        ?? ''
                    ),
                    2
                )[0]
            )
        );

        if ($contentType !== 'application/json') {
            throw new MiniAppException(
                'بدنه درخواست باید JSON باشد.',
                'content_type_invalid',
                415
            );
        }

        $raw = file_get_contents('php://input');

        if ($raw === false) {
            throw new MiniAppException(
                'بدنه درخواست قابل خواندن نیست.',
                'request_body_unreadable',
                400
            );
        }

        $maxBodyBytes = max(
            4096,
            min(
                1048576,
                (int) $runtime->get(
                    'modules.mini_app.security.max_request_bytes',
                    131072
                )
            )
        );

        if (strlen($raw) > $maxBodyBytes) {
            throw new MiniAppException(
                'بدنه درخواست بیش از حد بزرگ است.',
                'request_body_too_large',
                413
            );
        }

        if (trim($raw) !== '') {
            try {
                $decoded = json_decode(
                    $raw,
                    true,
                    64,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                throw new MiniAppException(
                    'JSON درخواست معتبر نیست.',
                    'request_json_invalid',
                    400
                );
            }

            if (!is_array($decoded)) {
                throw new MiniAppException(
                    'ساختار JSON درخواست معتبر نیست.',
                    'request_payload_invalid',
                    400
                );
            }

            $body = $decoded;
        }
    }

    if ($method === 'POST') {
        $origin = trim(
            (string) (
                $_SERVER['HTTP_ORIGIN'] ?? ''
            )
        );

        if ($origin !== '') {
            $miniAppUrl = (string) $runtime->get(
                'modules.mini_app.url',
                'https://alirezanobakht2004.alwaysdata.net/app/'
            );
            $originParts = parse_url($miniAppUrl);

            if (
                !is_array($originParts)
                || !isset(
                    $originParts['scheme'],
                    $originParts['host']
                )
            ) {
                throw new MiniAppException(
                    'آدرس Mini App برای بررسی Origin معتبر نیست.',
                    'mini_app_url_invalid',
                    500
                );
            }

            $expectedOrigin = mb_strtolower(
                (string) $originParts['scheme']
                . '://'
                . (string) $originParts['host']
                . (
                    isset($originParts['port'])
                        ? ':' . (int) $originParts['port']
                        : ''
                )
            );

            if (!hash_equals(
                $expectedOrigin,
                mb_strtolower($origin)
            )) {
                throw new MiniAppException(
                    'Origin درخواست معتبر نیست.',
                    'origin_invalid',
                    403
                );
            }
        }
    }

    if ($action === 'auth') {
        if ($method !== 'POST') {
            throw new MiniAppException(
                'احراز هویت باید با POST انجام شود.',
                'method_not_allowed',
                405
            );
        }

        $authLimit = $limiter->attempt(
            'mini-app-auth:' . $ipAddress,
            (int) $runtime->get(
                'modules.mini_app.rate_limit.auth_max_attempts',
                20
            ),
            (int) $runtime->get(
                'modules.mini_app.rate_limit.auth_window_seconds',
                300
            )
        );

        if (!$authLimit['allowed']) {
            header(
                'Retry-After: '
                . $authLimit['retry_after']
            );

            throw new MiniAppException(
                'تعداد تلاش‌های ورود زیاد است؛ کمی بعد دوباره تلاش کن.',
                'auth_rate_limited',
                429
            );
        }

        $validator = new InitDataValidator(
            botToken: (string) $config->get(
                'telegram.token'
            ),
            maxAgeSeconds: (int) $runtime->get(
                'modules.mini_app.security.init_data_max_age_seconds',
                300
            ),
            futureSkewSeconds: (int) $runtime->get(
                'modules.mini_app.security.auth_date_future_skew_seconds',
                30
            ),
            maxBytes: (int) $runtime->get(
                'modules.mini_app.security.max_init_data_bytes',
                16384
            )
        );

        $validated = $validator->validate(
            (string) ($body['init_data'] ?? '')
        );

        $repository->ensureUserAndPrivateChat(
            $validated['user']
        );

        $oldToken = $_COOKIE[$cookieName] ?? null;

        if (is_string($oldToken)) {
            $sessions->revokeToken(
                $oldToken,
                'reauthenticated'
            );
        }

        $created = $sessions->create(
            userId: (int) $validated['user']['id'],
            initDataHash: $validated['init_data_hash'],
            ipAddress: $ipAddress,
            userAgent: $userAgent
        );

        $session = $created['session'];
        $sessionToken = $created['token'];
        $userId = (int) $validated['user']['id'];

        setcookie(
            $cookieName,
            $sessionToken,
            [
                'expires' => (int) $session['absolute_expires_at'],
                'path' => '/app',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]
        );

        $audit->record(
            userId: $userId,
            sessionId: (int) $session['id'],
            action: 'auth.login',
            success: true,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            details: [
                'auth_date' => $validated['auth_date'],
                'has_query_id' => $validated['query_id'] !== null,
                'start_param' => $validated['start_param'],
            ]
        );

        $respond([
            'ok' => true,
            'data' => [
                'csrf_token' => $created['csrf_token'],
                'session_expires_at' => (int) $session['expires_at'],
                'session_absolute_expires_at' => (int) $session['absolute_expires_at'],
                'user' => $repository->profile($userId),
                'settings' => $repository->settings($userId),
                'dashboard' => $repository->dashboard($userId),
                'app' => [
                    'name' => (string) $config->get(
                        'app.name',
                        'جعبه ابزار'
                    ),
                    'bot_username' => (string) $config->get(
                        'telegram.username',
                        'SmartToolboxFaBot'
                    ),
                    'url' => (string) $runtime->get(
                        'modules.mini_app.url',
                        'https://alirezanobakht2004.alwaysdata.net/app/'
                    ),
                ],
            ],
            'server_time' => time(),
        ]);
    }

    $sessionToken = $_COOKIE[$cookieName] ?? null;

    if (!is_string($sessionToken)) {
        throw new MiniAppException(
            'Session Mini App وجود ندارد؛ برنامه را دوباره باز کن.',
            'session_cookie_missing',
            401
        );
    }

    $session = $sessions->authenticate(
        $sessionToken,
        $userAgent
    );
    $userId = (int) $session['user_id'];

    $apiLimit = $limiter->attempt(
        'mini-app-api:'
        . $userId
        . ':'
        . $action,
        (int) $runtime->get(
            'modules.mini_app.rate_limit.api_max_attempts',
            120
        ),
        (int) $runtime->get(
            'modules.mini_app.rate_limit.api_window_seconds',
            60
        )
    );

    if (!$apiLimit['allowed']) {
        header(
            'Retry-After: '
            . $apiLimit['retry_after']
        );

        throw new MiniAppException(
            'درخواست‌های Mini App زیاد است؛ کمی بعد دوباره تلاش کن.',
            'api_rate_limited',
            429
        );
    }

    if ($method === 'POST') {
        $sessions->verifyCsrf(
            $session,
            (string) (
                $_SERVER['HTTP_X_CSRF_TOKEN']
                ?? ''
            )
        );
    }

    if ($action === 'logout') {
        if ($method !== 'POST') {
            throw new MiniAppException(
                'خروج باید با POST انجام شود.',
                'method_not_allowed',
                405
            );
        }

        $sessions->revokeToken(
            $sessionToken,
            'logout'
        );
        $clearCookie($cookieName);

        $audit->record(
            userId: $userId,
            sessionId: (int) $session['id'],
            action: 'auth.logout',
            success: true,
            ipAddress: $ipAddress,
            userAgent: $userAgent
        );

        $respond([
            'ok' => true,
            'data' => ['logged_out' => true],
            'server_time' => time(),
        ]);
    }

    $monitorGuard = new SsrfGuard(
        allowHttp: true,
        allowedPorts: (array) $runtime->get(
            'modules.monitoring.http.allowed_ports',
            [80, 443]
        )
    );

    $controller = new MiniAppApiController(
        repository: $repository,
        schedule: new ScheduleCalculator(),
        monitorProbe: new MonitorProbe(
            userAgent: (string) $config->get(
                'http.user_agent',
                'SmartToolboxFaBot/1.0'
            ),
            guard: $monitorGuard,
            connectTimeout: (int) $runtime->get(
                'modules.monitoring.http.connect_timeout',
                4
            ),
            timeout: (int) $runtime->get(
                'modules.monitoring.http.timeout',
                8
            ),
            maxResponseBytes: (int) $runtime->get(
                'modules.monitoring.http.max_response_bytes',
                131072
            ),
            maxRedirects: (int) $runtime->get(
                'modules.monitoring.http.max_redirects',
                3
            )
        ),
        limits: [
            'reminder_max_future_days' => (int) $runtime->get(
                'modules.reminders.max_future_days',
                365
            ),
            'reminder_max_pending' => (int) $runtime->get(
                'modules.reminders.max_pending_per_user',
                50
            ),
            'reminder_max_text_length' => (int) $runtime->get(
                'modules.reminders.max_text_length',
                1000
            ),
            'max_alerts_per_user' => (int) $runtime->get(
                'modules.alerts.max_alerts_per_user',
                30
            ),
            'max_subscriptions_per_user' => (int) $runtime->get(
                'modules.alerts.max_subscriptions_per_user',
                20
            ),
            'default_cooldown_seconds' => (int) $runtime->get(
                'modules.alerts.default_cooldown_seconds',
                3600
            ),
            'default_hysteresis' => (float) $runtime->get(
                'modules.alerts.default_hysteresis',
                0.5
            ),
            'max_notifications_per_day' => (int) $runtime->get(
                'modules.alerts.max_notifications_per_day',
                3
            ),
            'alert_check_interval_seconds' => (int) $runtime->get(
                'modules.alerts.check_interval_seconds',
                300
            ),
            'max_monitors_per_user' => (int) $runtime->get(
                'modules.monitoring.max_monitors_per_user',
                20
            ),
            'monitor_minimum_interval_seconds' => (int) $runtime->get(
                'modules.monitoring.minimum_interval_seconds',
                300
            ),
            'monitor_maximum_interval_seconds' => (int) $runtime->get(
                'modules.monitoring.maximum_interval_seconds',
                86400
            ),
            'max_favorites' => (int) $runtime->get(
                'modules.profile.max_favorites',
                50
            ),
            'max_shortcuts' => (int) $runtime->get(
                'modules.profile.max_shortcuts',
                30
            ),
        ]
    );

    $result = $controller->dispatch(
        $action,
        $method,
        $body,
        $userId
    );

    $resourceId = null;

    if (isset($body['id'])) {
        $resourceId = (string) $body['id'];
    } elseif (isset($result['id'])) {
        $resourceId = (string) $result['id'];
    }

    $audit->record(
        userId: $userId,
        sessionId: (int) $session['id'],
        action: $action,
        success: true,
        ipAddress: $ipAddress,
        userAgent: $userAgent,
        resourceType: str_contains($action, '.')
            ? strstr($action, '.', true) ?: null
            : $action,
        resourceId: $resourceId,
        details: [
            'method' => $method,
        ]
    );

    $respond([
        'ok' => true,
        'data' => $result,
        'server_time' => time(),
        'rate_limit' => [
            'remaining' => $apiLimit['remaining'],
        ],
    ]);
} catch (MiniAppException $exception) {
    if ($exception->httpStatus === 401) {
        $clearCookie($cookieName);
    }

    if ($audit instanceof MiniAppAuditLogger) {
        try {
            $audit->record(
                userId: $userId,
                sessionId: is_array($session)
                    ? (int) ($session['id'] ?? 0) ?: null
                    : null,
                action: $action !== ''
                    ? $action
                    : 'unknown',
                success: false,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                resourceType: null,
                resourceId: null,
                errorCode: $exception->errorCode,
                details: [
                    'method' => $method,
                    'http_status' => $exception->httpStatus,
                ]
            );
        } catch (Throwable) {
        }
    }

    $respond([
        'ok' => false,
        'error' => [
            'code' => $exception->errorCode,
            'message' => $exception->getMessage(),
        ],
        'server_time' => time(),
    ], $exception->httpStatus);
} catch (Throwable $exception) {
    if ($audit instanceof MiniAppAuditLogger) {
        try {
            $audit->record(
                userId: $userId,
                sessionId: is_array($session)
                    ? (int) ($session['id'] ?? 0) ?: null
                    : null,
                action: $action !== ''
                    ? $action
                    : 'unknown',
                success: false,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                errorCode: 'internal_error',
                details: [
                    'method' => $method,
                    'exception' => $exception::class,
                ]
            );
        } catch (Throwable) {
        }
    }

    error_log(
        '[mini-app] '
        . $exception->getMessage()
        . "\n"
        . $exception->getTraceAsString()
    );

    $respond([
        'ok' => false,
        'error' => [
            'code' => 'internal_error',
            'message' => 'خطای داخلی Mini App رخ داد.',
        ],
        'server_time' => time(),
    ], 500);
}
