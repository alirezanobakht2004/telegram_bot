<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use JsonException;
use RuntimeException;

final class TelegramClient
{
    private readonly string $baseUrl;

    public function __construct(
        private readonly string $token
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

        $statusCode = (int) curl_getinfo(
            $handle,
            CURLINFO_HTTP_CODE
        );

        curl_close($handle);

        if ($response === false) {
            throw new RuntimeException(
                'Telegram connection failed: ' . $curlError
            );
        }

        try {
            $data = json_decode(
                $response,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
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

            throw new RuntimeException(
                sprintf(
                    'Telegram API error (%d): %s',
                    $statusCode,
                    $description
                )
            );
        }

        return $data['result'] ?? null;
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
     * @param int|string           $chatId
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        array $options = []
    ): array {
        $parameters = [
            'chat_id' => $chatId,
            'text' => $text,
        ] + $options;

        $result = $this->call(
            'sendMessage',
            $parameters
        );

        if (!is_array($result)) {
            throw new RuntimeException(
                'Telegram sendMessage returned an invalid result.'
            );
        }

        return $result;
    }

    /**
     * @param int|string           $chatId
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

        $parameters = [
            'chat_id' => $chatId,
            'photo' => $photo,
        ] + $options;

        $result = $this->call(
            'sendPhoto',
            $parameters
        );

        if (!is_array($result)) {
            throw new RuntimeException(
                'Telegram sendPhoto returned an invalid result.'
            );
        }

        return $result;
    }
}
