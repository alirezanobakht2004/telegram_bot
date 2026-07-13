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
     * @return array<string, mixed>
     */
    public function getFileInfo(string $fileId): array
    {
        if (trim($fileId) === '') {
            throw new RuntimeException(
                'Telegram file_id cannot be empty.'
            );
        }

        $result = $this->call(
            'getFile',
            ['file_id' => $fileId]
        );

        if (!is_array($result)) {
            throw new RuntimeException(
                'Telegram getFile returned an invalid result.'
            );
        }

        return $result;
    }

    public function downloadFile(
        string $fileId,
        string $destination,
        int $maxBytes,
        int $timeoutSeconds = 30
    ): int {
        $file = $this->getFileInfo($fileId);
        $filePath = $file['file_path'] ?? null;
        $knownSize = $file['file_size'] ?? null;
        $maxBytes = max(1, $maxBytes);

        if (
            is_int($knownSize)
            && $knownSize > $maxBytes
        ) {
            throw new RuntimeException(
                'Telegram file exceeds the configured size limit.'
            );
        }

        if (
            !is_string($filePath)
            || $filePath === ''
            || str_contains($filePath, '..')
            || preg_match(
                '#^[A-Za-z0-9_./-]{1,512}$#',
                $filePath
            ) !== 1
        ) {
            throw new RuntimeException(
                'Telegram returned an invalid file path.'
            );
        }

        $directory = dirname($destination);

        if (
            !is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                'Download directory could not be created.'
            );
        }

        $handle = fopen($destination, 'w+b');

        if ($handle === false) {
            throw new RuntimeException(
                'Download destination could not be opened.'
            );
        }

        @chmod($destination, 0600);

        $curl = curl_init(
            'https://api.telegram.org/file/bot'
            . $this->token
            . '/'
            . ltrim($filePath, '/')
        );

        if ($curl === false) {
            fclose($handle);
            throw new RuntimeException(
                'Could not initialize Telegram file download.'
            );
        }

        $bytes = 0;
        $startedAt = hrtime(true);
        $statusCode = null;
        $success = false;
        $errorCode = null;

        try {
            $options = [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => max(1, min(60, $timeoutSeconds)),
                CURLOPT_NOSIGNAL => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/octet-stream',
                ],
                CURLOPT_WRITEFUNCTION => static function (
                    mixed $curlHandle,
                    string $chunk
                ) use (
                    $handle,
                    &$bytes,
                    $maxBytes
                ): int {
                    $length = strlen($chunk);

                    if ($bytes + $length > $maxBytes) {
                        return 0;
                    }

                    $written = fwrite($handle, $chunk);

                    if ($written === false) {
                        return 0;
                    }

                    $bytes += $written;

                    return $written;
                },
            ];

            if (
                defined('CURLOPT_PROTOCOLS')
                && defined('CURLPROTO_HTTPS')
            ) {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            }

            curl_setopt_array($curl, $options);

            $executed = curl_exec($curl);
            $statusCode = (int) curl_getinfo(
                $curl,
                CURLINFO_HTTP_CODE
            );

            if ($executed === false) {
                $curlError = curl_error($curl);
                $curlNumber = curl_errno($curl);
                $errorCode = 'curl_' . $curlNumber;

                if ($bytes >= $maxBytes) {
                    throw new RuntimeException(
                        'Downloaded file exceeds the configured size limit.'
                    );
                }

                throw new RuntimeException(
                    'Telegram file download failed: ' . $curlError
                );
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                $errorCode = 'http_' . $statusCode;
                throw new RuntimeException(
                    'Telegram file download returned HTTP '
                    . $statusCode
                    . '.'
                );
            }

            fflush($handle);
            $success = true;

            return $bytes;
        } catch (Throwable $exception) {
            $errorCode ??= $exception::class;
            @unlink($destination);
            throw $exception;
        } finally {
            fclose($handle);
            curl_close($curl);

            $this->metrics?->record(
                provider: 'telegram_file',
                method: 'GET',
                host: 'api.telegram.org',
                path: '/file/bot{token}/' . basename($filePath),
                statusCode: $statusCode,
                durationMs: max(
                    0.0,
                    (hrtime(true) - $startedAt) / 1_000_000
                ),
                responseBytes: $bytes,
                success: $success,
                errorCode: $errorCode
            );
        }
    }

    /**
     * @param int|string $chatId
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function sendDocumentFile(
        int|string $chatId,
        string $path,
        string $filename,
        array $options = []
    ): array {
        return $this->uploadFile(
            method: 'sendDocument',
            field: 'document',
            chatId: $chatId,
            path: $path,
            filename: $filename,
            options: $options
        );
    }

    /**
     * @param int|string $chatId
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function sendPhotoFile(
        int|string $chatId,
        string $path,
        string $filename,
        array $options = []
    ): array {
        return $this->uploadFile(
            method: 'sendPhoto',
            field: 'photo',
            chatId: $chatId,
            path: $path,
            filename: $filename,
            options: $options
        );
    }

    /**
     * @param int|string $chatId
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function uploadFile(
        string $method,
        string $field,
        int|string $chatId,
        string $path,
        string $filename,
        array $options
    ): array {
        $realPath = realpath($path);

        if ($realPath === false || !is_file($realPath)) {
            throw new RuntimeException(
                'Upload file does not exist.'
            );
        }

        $filename = trim($filename) !== ''
            ? mb_substr(basename($filename), 0, 255)
            : basename($realPath);

        $mime = function_exists('mime_content_type')
            ? (mime_content_type($realPath) ?: 'application/octet-stream')
            : 'application/octet-stream';

        $timeoutSeconds = max(
            1,
            min(
                60,
                (int) ($options['_timeout_seconds'] ?? 45)
            )
        );
        unset($options['_timeout_seconds']);

        $fields = [
            'chat_id' => (string) $chatId,
            $field => new \CURLFile(
                $realPath,
                $mime,
                $filename
            ),
        ];

        foreach ($options as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value) || is_object($value)) {
                try {
                    $fields[$key] = json_encode(
                        $value,
                        JSON_THROW_ON_ERROR
                        | JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                    );
                } catch (JsonException $exception) {
                    throw new RuntimeException(
                        'Telegram multipart option could not be encoded.',
                        previous: $exception
                    );
                }
            } elseif (is_bool($value)) {
                $fields[$key] = $value ? 'true' : 'false';
            } else {
                $fields[$key] = (string) $value;
            }
        }

        $result = $this->callMultipart(
            $method,
            $fields,
            $timeoutSeconds
        );

        if (!is_array($result)) {
            throw new RuntimeException(
                'Telegram upload returned an invalid result.'
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function callMultipart(
        string $method,
        array $fields,
        int $timeoutSeconds = 45
    ): mixed {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $method)) {
            throw new RuntimeException(
                'Invalid Telegram API method name.'
            );
        }

        $handle = curl_init(
            $this->baseUrl . '/' . $method
        );

        if ($handle === false) {
            throw new RuntimeException(
                'Could not initialize Telegram multipart request.'
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
                CURLOPT_POSTFIELDS => $fields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => max(1, min(60, $timeoutSeconds)),
                CURLOPT_NOSIGNAL => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($handle);
            $statusCode = (int) curl_getinfo(
                $handle,
                CURLINFO_HTTP_CODE
            );

            if ($response === false) {
                $errorCode = 'curl_' . curl_errno($handle);
                throw new RuntimeException(
                    'Telegram upload failed: ' . curl_error($handle)
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
                    'Telegram upload returned invalid JSON.',
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
                    ? (string) ($data['description'] ?? 'Unknown Telegram error')
                    : 'Unknown Telegram error';
                $errorCode = is_array($data) && isset($data['error_code'])
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

            $this->metrics?->record(
                provider: 'telegram',
                method: 'POST',
                host: 'api.telegram.org',
                path: '/bot{token}/' . $method,
                statusCode: $statusCode,
                durationMs: max(
                    0.0,
                    (hrtime(true) - $startedAt) / 1_000_000
                ),
                responseBytes: $responseBytes,
                success: $success,
                errorCode: $errorCode
            );
        }
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
