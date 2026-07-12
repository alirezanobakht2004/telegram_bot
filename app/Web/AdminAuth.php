<?php

declare(strict_types=1);

namespace SmartToolbox\Web;

use PDO;
use RuntimeException;

final class AdminAuth
{
    private const AUTH = 'web_admin_authenticated';
    private const LOGIN_AT = 'web_admin_login_at';
    private const LAST_ACTIVITY = 'web_admin_last_activity';
    private const FINGERPRINT = 'web_admin_fingerprint';
    private const CSRF = 'web_admin_csrf';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $passwordHash,
        private readonly string $sessionName,
        private readonly int $idleSeconds,
        private readonly int $absoluteSeconds,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds,
        private readonly int $blockSeconds,
        private readonly bool $secureCookie,
        private readonly string $cookiePath = '/admin'
    ) {
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');

        $name = preg_replace(
            '/[^A-Za-z0-9_]/',
            '_',
            $this->sessionName
        ) ?? '';

        session_name(
            $name !== ''
                ? $name
                : 'smart_toolbox_admin'
        );

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->cookiePath,
            'secure' => $this->secureCookie,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        if (!session_start()) {
            throw new RuntimeException(
                'Admin session could not be started.'
            );
        }
    }

    public function configured(): bool
    {
        $info = password_get_info(
            $this->passwordHash
        );

        return trim($this->passwordHash) !== ''
            && ($info['algo'] ?? null) !== null;
    }

    public function authenticated(
        string $userAgent
    ): bool {
        if (
            ($_SESSION[self::AUTH] ?? false)
            !== true
        ) {
            return false;
        }

        $now = time();
        $loginAt = (int) (
            $_SESSION[self::LOGIN_AT] ?? 0
        );
        $lastActivity = (int) (
            $_SESSION[self::LAST_ACTIVITY]
            ?? 0
        );

        if (
            $loginAt <= 0
            || $lastActivity <= 0
            || $now - $lastActivity
                > $this->safeIdle()
            || $now - $loginAt
                > $this->safeAbsolute()
        ) {
            $this->logout();

            return false;
        }

        $stored = (string) (
            $_SESSION[self::FINGERPRINT]
            ?? ''
        );

        if (
            $stored === ''
            || !hash_equals(
                $stored,
                $this->fingerprint($userAgent)
            )
        ) {
            $this->logout();

            return false;
        }

        $_SESSION[self::LAST_ACTIVITY] = $now;

        return true;
    }

    /**
     * @return array{
     *     success: bool,
     *     error: string
     * }
     */
    public function login(
        string $password,
        string $ipAddress,
        string $userAgent
    ): array {
        if (!$this->configured()) {
            return [
                'success' => false,
                'error' => 'رمز پنل هنوز تنظیم نشده است.',
            ];
        }

        $identifier = hash(
            'sha256',
            trim($ipAddress) !== ''
                ? trim($ipAddress)
                : 'unknown'
        );

        $retryAfter = $this->retryAfter(
            $identifier
        );

        if ($retryAfter > 0) {
            return [
                'success' => false,
                'error' =>
                    "ورود موقتاً مسدود است. {$retryAfter} ثانیه دیگر تلاش کن.",
            ];
        }

        if (
            !password_verify(
                $password,
                $this->passwordHash
            )
        ) {
            $blockedFor = $this->recordFailure(
                $identifier
            );

            return [
                'success' => false,
                'error' => $blockedFor > 0
                    ? "تلاش ناموفق زیاد بود. {$blockedFor} ثانیه مسدود شد."
                    : 'رمز عبور صحیح نیست.',
            ];
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM admin_login_attempts
             WHERE identifier = :identifier'
        );
        $statement->execute([
            'identifier' => $identifier,
        ]);

        session_regenerate_id(true);

        $_SESSION[self::AUTH] = true;
        $_SESSION[self::LOGIN_AT] = time();
        $_SESSION[self::LAST_ACTIVITY] = time();
        $_SESSION[self::FINGERPRINT] =
            $this->fingerprint($userAgent);
        $_SESSION[self::CSRF] =
            bin2hex(random_bytes(32));

        return [
            'success' => true,
            'error' => '',
        ];
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (
            session_status()
            !== PHP_SESSION_ACTIVE
        ) {
            return;
        }

        $parameters =
            session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $parameters['path'],
                'domain' => $parameters['domain'],
                'secure' => $parameters['secure'],
                'httponly' =>
                    $parameters['httponly'],
                'samesite' => 'Strict',
            ]
        );

        session_destroy();
    }

    public function csrfToken(): string
    {
        $token = $_SESSION[self::CSRF]
            ?? null;

        if (!is_string($token) || $token === '') {
            $token = bin2hex(
                random_bytes(32)
            );

            $_SESSION[self::CSRF] = $token;
        }

        return $token;
    }

    public function validateCsrf(
        mixed $token
    ): bool {
        return is_string($token)
            && $token !== ''
            && hash_equals(
                $this->csrfToken(),
                $token
            );
    }

    public function rotateCsrf(): void
    {
        $_SESSION[self::CSRF] =
            bin2hex(random_bytes(32));
    }

    private function retryAfter(
        string $identifier
    ): int {
        $statement = $this->pdo->prepare(
            'SELECT blocked_until
             FROM admin_login_attempts
             WHERE identifier = :identifier
             LIMIT 1'
        );
        $statement->execute([
            'identifier' => $identifier,
        ]);

        $value = $statement->fetchColumn();

        return is_numeric($value)
            ? max(0, (int) $value - time())
            : 0;
    }

    private function recordFailure(
        string $identifier
    ): int {
        $now = time();

        $statement = $this->pdo->prepare(
            'SELECT attempts, window_started_at
             FROM admin_login_attempts
             WHERE identifier = :identifier
             LIMIT 1'
        );
        $statement->execute([
            'identifier' => $identifier,
        ]);

        $row = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        $attempts = is_array($row)
            ? (int) ($row['attempts'] ?? 0)
            : 0;
        $started = is_array($row)
            ? (int) (
                $row['window_started_at']
                ?? 0
            )
            : 0;

        if (
            $started <= 0
            || $now - $started
                >= $this->safeWindow()
        ) {
            $attempts = 0;
            $started = $now;
        }

        $attempts++;

        $blockedUntil = $attempts
            >= $this->safeAttempts()
            ? $now + $this->safeBlock()
            : 0;

        $upsert = $this->pdo->prepare(
            'INSERT INTO admin_login_attempts (
                identifier,
                attempts,
                window_started_at,
                blocked_until,
                updated_at
            ) VALUES (
                :identifier,
                :attempts,
                :window_started_at,
                :blocked_until,
                :updated_at
            )
            ON CONFLICT(identifier) DO UPDATE SET
                attempts = excluded.attempts,
                window_started_at =
                    excluded.window_started_at,
                blocked_until =
                    excluded.blocked_until,
                updated_at = excluded.updated_at'
        );
        $upsert->execute([
            'identifier' => $identifier,
            'attempts' => $attempts,
            'window_started_at' => $started,
            'blocked_until' => $blockedUntil,
            'updated_at' => date(DATE_ATOM),
        ]);

        return max(
            0,
            $blockedUntil - $now
        );
    }

    private function fingerprint(
        string $userAgent
    ): string {
        return hash(
            'sha256',
            mb_substr(
                trim($userAgent),
                0,
                500
            )
        );
    }

    private function safeIdle(): int
    {
        return max(
            300,
            min(86400, $this->idleSeconds)
        );
    }

    private function safeAbsolute(): int
    {
        return max(
            $this->safeIdle(),
            min(
                604800,
                $this->absoluteSeconds
            )
        );
    }

    private function safeAttempts(): int
    {
        return max(
            3,
            min(20, $this->maxAttempts)
        );
    }

    private function safeWindow(): int
    {
        return max(
            60,
            min(86400, $this->windowSeconds)
        );
    }

    private function safeBlock(): int
    {
        return max(
            60,
            min(86400, $this->blockSeconds)
        );
    }
}
