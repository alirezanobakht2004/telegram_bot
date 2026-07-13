<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use JsonException;
use RuntimeException;
use Throwable;

final class TelegramClient
{
    private readonly string $baseUrl;

    public function __construct(
        private readonly string $token,
        private readonly ?ApiMetricsTracker $metrics = null
    ) {
        if (
            trim($this->token) === ''
            || str_contains($this->token, 'PUT_BOT_TOKEN')
        ) {
            throw new RuntimeException(
                'Telegram bot token is not configured.'
            );
        }

        $this->baseUrl = sprintf(
            'https://api.telegram.org/bot%s',
            $this->token
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function call(
        string $method,
        array $parameters = []
    ): mixed {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $method)) {
            throw new RuntimeException(
                'Invalid Telegram API method name.'
            );
        }

        try {
            $payload = json_encode(
                $parameters,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Could not encode Telegram request.',
                previous: $exception
            );
        }

        $handle = curl_init(
            $this->baseUrl . '/' . $method
        );

        if ($handle === false) {
            throw new RuntimeException(
                'Could not initialize cURL.'
            );
        }

        $startedAt = hrtime(true);
        $statusCode = null;
        $responseBytes = 0;
        $success = false;
        $errorCode = null;

        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($handle);
            $curlError = curl_error($handle);
            $curlErrorNumber = curl_errno($handle);

            $statusCode = (int) curl_getinfo(
                $handle,
                CURLINFO_HTTP_CODE
            );

            if ($response === false) {
                $errorCode = 'curl_' . $curlErrorNumber;

                throw new RuntimeException(
                    'Telegram connection failed: ' . $curlError
                );
            }

            $responseBytes = strlen($response);

            try {
                $data = json_decode(
                    $response,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException $exception) {
                $errorCode = 'invalid_json';

                throw new RuntimeException(
                    'Telegram returned invalid JSON.',
                    previous: $exception
                );
            }

            if (
                $statusCode < 200
                || $statusCode >= 300
                || !is_array($data)
                || ($data['ok'] ?? false) !== true
            ) {
                $description = is_array($data)
                    ? (string) (
                        $data['description']
                        ?? 'Unknown Telegram error'
                    )
                    : 'Unknown Telegram error';

                $errorCode = is_array($data)
                    && isset($data['error_code'])
                    ? 'telegram_' . (int) $data['error_code']
                    : 'telegram_api_error';

                throw new RuntimeException(
                    sprintf(
                        'Telegram API error (%d): %s',
                        $statusCode,
                        $description
                    )
                );
            }

            $success = true;

            return $data['result'] ?? null;
        } catch (Throwable $exception) {
            $errorCode ??= $exception::class;
            throw $exception;
        } finally {
            curl_close($handle);

            $durationMs = max(
                0.0,
                (hrtime(true) - $startedAt) / 1_000_000
            );

            $this->metrics?->record(
                provider: 'telegram',
                method: 'POST',
                host: 'api.telegram.org',
                path: '/bot{token}/' . $method,
                statusCode: $statusCode,
                durationMs: $durationMs,
                responseBytes: $responseBytes,
                success: $success,
                errorCode: $errorCode
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getMe(): array
    {
        $result = $this->call('getMe');

        if (!is_array($result)) {
            throw new RuntimeException(
                'Telegram getMe returned an invalid result.'
            );
        }

        return $result;
    }

    /**
     * @param int|string $chatId
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        array $options = []
    ): array {
        $result = $this->call(
            'sendMessage',
            [
                'chat_id' => $chatId,
                'text' => $text,
            ] + $options
        );

        if (!is_array($result)) {
            throw new RuntimeException(
                'Telegram sendMessage returned an invalid result.'
            );
        }

        return $result;
    }

    /**
     * @param int|string $chatId
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function sendPhoto(
        int|string $chatId,
        string $photo,
        array $options = []
    ): array {
        if (trim($photo) === '') {
            throw new RuntimeException(
                'Telegram photo value cannot be empty.'
            );
        }

        $result = $this->call(
            'sendPhoto',
            [
                'chat_id' => $chatId,
                'photo' => $photo,
            ] + $options
        );

        if (!is_array($result)) {
            throw new RuntimeException(
                'Telegram sendPhoto returned an invalid result.'
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function answerCallbackQuery(
        string $callbackQueryId,
        array $options = []
    ): bool {
        $result = $this->call(
            'answerCallbackQuery',
            [
                'callback_query_id' => $callbackQueryId,
            ] + $options
        );

        return $result === true;
    }

    /**
     * @param list<array<string, mixed>> $results
     * @param array<string, mixed> $options
     */
    public function answerInlineQuery(
        string $inlineQueryId,
        array $results,
        array $options = []
    ): bool {
        if (count($results) > 50) {
            throw new RuntimeException(
                'Telegram inline query results cannot exceed 50 items.'
            );
        }

        $result = $this->call(
            'answerInlineQuery',
            [
                'inline_query_id' => $inlineQueryId,
                'results' => array_values($results),
            ] + $options
        );

        return $result === true;
    }
}
