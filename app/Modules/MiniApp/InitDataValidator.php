<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\MiniApp;

use JsonException;

final class InitDataValidator
{
    public function __construct(
        private readonly string $botToken,
        private readonly int $maxAgeSeconds = 300,
        private readonly int $futureSkewSeconds = 30,
        private readonly int $maxBytes = 16384
    ) {
        if (
            trim($this->botToken) === ''
            || str_contains(
                $this->botToken,
                'PUT_BOT_TOKEN'
            )
        ) {
            throw new MiniAppException(
                'توکن ربات برای Mini App تنظیم نشده است.',
                'bot_token_missing',
                500
            );
        }
    }

    /**
     * @return array{
     *     user:array<string,mixed>,
     *     auth_date:int,
     *     query_id:?string,
     *     start_param:?string,
     *     fields:array<string,string>,
     *     init_data_hash:string
     * }
     */
    public function validate(
        string $initData,
        ?int $now = null
    ): array {
        $initData = trim($initData);

        if ($initData === '') {
            throw new MiniAppException(
                'اطلاعات ورود Telegram دریافت نشد.',
                'init_data_missing',
                401
            );
        }

        if (strlen($initData) > max(1024, $this->maxBytes)) {
            throw new MiniAppException(
                'اطلاعات ورود Telegram بیش از حد بزرگ است.',
                'init_data_too_large',
                413
            );
        }

        $fields = $this->parseQuery($initData);
        $receivedHash = $fields['hash'] ?? null;

        if (
            !is_string($receivedHash)
            || preg_match(
                '/^[a-f0-9]{64}$/i',
                $receivedHash
            ) !== 1
        ) {
            throw new MiniAppException(
                'امضای اطلاعات Telegram معتبر نیست.',
                'init_data_hash_invalid',
                401
            );
        }

        unset($fields['hash']);
        ksort($fields, SORT_STRING);

        $pairs = [];

        foreach ($fields as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        $dataCheckString = implode("\n", $pairs);

        $secretKey = hash_hmac(
            'sha256',
            $this->botToken,
            'WebAppData',
            true
        );

        $calculatedHash = hash_hmac(
            'sha256',
            $dataCheckString,
            $secretKey
        );

        if (!hash_equals(
            mb_strtolower($receivedHash),
            mb_strtolower($calculatedHash)
        )) {
            throw new MiniAppException(
                'اعتبارسنجی امضای Telegram ناموفق بود.',
                'init_data_signature_mismatch',
                401
            );
        }

        $authDateRaw = $fields['auth_date'] ?? '';

        if (
            preg_match('/^\d{1,12}$/', $authDateRaw) !== 1
        ) {
            throw new MiniAppException(
                'زمان احراز هویت Telegram معتبر نیست.',
                'auth_date_invalid',
                401
            );
        }

        $authDate = (int) $authDateRaw;
        $now ??= time();

        if (
            $authDate > $now + max(0, $this->futureSkewSeconds)
        ) {
            throw new MiniAppException(
                'زمان احراز هویت Telegram در آینده است.',
                'auth_date_in_future',
                401
            );
        }

        if (
            $now - $authDate
            > max(30, $this->maxAgeSeconds)
        ) {
            throw new MiniAppException(
                'اطلاعات ورود Telegram منقضی شده است؛ Mini App را دوباره باز کن.',
                'init_data_expired',
                401
            );
        }

        $userJson = $fields['user'] ?? null;

        if (!is_string($userJson) || $userJson === '') {
            throw new MiniAppException(
                'اطلاعات کاربر Telegram وجود ندارد.',
                'init_data_user_missing',
                401
            );
        }

        try {
            $user = json_decode(
                $userJson,
                true,
                64,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new MiniAppException(
                'اطلاعات کاربر Telegram خراب است.',
                'init_data_user_invalid',
                401
            );
        }

        if (!is_array($user)) {
            throw new MiniAppException(
                'ساختار کاربر Telegram معتبر نیست.',
                'init_data_user_invalid',
                401
            );
        }

        $userId = $user['id'] ?? null;

        if (
            !is_int($userId)
            || $userId <= 0
        ) {
            throw new MiniAppException(
                'شناسه کاربر Telegram معتبر نیست.',
                'telegram_user_id_invalid',
                401
            );
        }

        if (($user['is_bot'] ?? false) === true) {
            throw new MiniAppException(
                'حساب ربات نمی‌تواند وارد Mini App شود.',
                'telegram_bot_user_rejected',
                403
            );
        }

        $normalizedUser = [
            'id' => $userId,
            'is_bot' => false,
            'first_name' => mb_substr(
                trim((string) ($user['first_name'] ?? '')),
                0,
                128
            ),
            'last_name' => isset($user['last_name'])
                ? mb_substr(
                    trim((string) $user['last_name']),
                    0,
                    128
                )
                : null,
            'username' => isset($user['username'])
                ? mb_substr(
                    trim((string) $user['username']),
                    0,
                    64
                )
                : null,
            'language_code' => isset($user['language_code'])
                ? mb_substr(
                    trim((string) $user['language_code']),
                    0,
                    16
                )
                : null,
            'is_premium' => array_key_exists('is_premium', $user)
                ? (bool) $user['is_premium']
                : null,
            'allows_write_to_pm' => array_key_exists(
                'allows_write_to_pm',
                $user
            )
                ? (bool) $user['allows_write_to_pm']
                : null,
            'photo_url' => isset($user['photo_url'])
                ? mb_substr(
                    trim((string) $user['photo_url']),
                    0,
                    2000
                )
                : null,
        ];

        if ($normalizedUser['first_name'] === '') {
            $normalizedUser['first_name'] = 'Telegram User';
        }

        return [
            'user' => $normalizedUser,
            'auth_date' => $authDate,
            'query_id' => isset($fields['query_id'])
                ? mb_substr($fields['query_id'], 0, 255)
                : null,
            'start_param' => isset($fields['start_param'])
                ? mb_substr($fields['start_param'], 0, 512)
                : null,
            'fields' => $fields,
            'init_data_hash' => hash(
                'sha256',
                $initData
            ),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function parseQuery(
        string $query
    ): array {
        $fields = [];

        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }

            [$rawKey, $rawValue] = array_pad(
                explode('=', $part, 2),
                2,
                ''
            );

            $key = urldecode($rawKey);
            $value = urldecode($rawValue);

            if (
                preg_match(
                    '/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/',
                    $key
                ) !== 1
            ) {
                throw new MiniAppException(
                    'کلید نامعتبر در اطلاعات Telegram وجود دارد.',
                    'init_data_key_invalid',
                    401
                );
            }

            if (array_key_exists($key, $fields)) {
                throw new MiniAppException(
                    'کلید تکراری در اطلاعات Telegram وجود دارد.',
                    'init_data_duplicate_key',
                    401
                );
            }

            $fields[$key] = $value;
        }

        return $fields;
    }
}
