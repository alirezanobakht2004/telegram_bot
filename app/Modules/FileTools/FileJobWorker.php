<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\FileTools;

use JsonException;
use SmartToolbox\Core\TelegramClient;
use SmartToolbox\Core\TemporaryFileManager;
use Throwable;

final class FileJobWorker
{
    public function __construct(
        private readonly FileJobRepository $jobs,
        private readonly TelegramClient $telegram,
        private readonly TemporaryFileManager $temporaryFiles,
        private readonly FileCapabilities $capabilities,
        private readonly FileInfoInspector $inspector,
        private readonly ImageProcessor $images,
        private readonly PdfTextExtractor $pdfText,
        private readonly TextFileProcessor $textFiles,
        private readonly QrCodeProcessor $qrCodes,
        private readonly int $maxFileBytes = 10485760,
        private readonly int $maxImagePixels = 12000000,
        private readonly int $timeoutSeconds = 45,
        private readonly string $logFile = ''
    ) {
    }

    public function process(int $fileJobId): void
    {
        $job = $this->jobs->claim($fileJobId);

        if ($job === null) {
            return;
        }

        $workspace = $this->temporaryFiles->createWorkspace(
            'file-job-' . $fileJobId
        );

        $deadline = microtime(true)
            + max(5, $this->timeoutSeconds);

        if (function_exists('set_time_limit')) {
            @set_time_limit(
                max(10, $this->timeoutSeconds + 15)
            );
        }

        try {
            $parameters = $this->decodeParameters(
                (string) ($job['parameters_json'] ?? '{}')
            );

            $result = $job['source_kind'] === 'text'
                ? $this->processTextJob(
                    $job,
                    $parameters,
                    $workspace,
                    $deadline
                )
                : $this->processTelegramFileJob(
                    $job,
                    $parameters,
                    $workspace,
                    $deadline
                );

            $this->assertDeadline($deadline);
            $this->jobs->progress($fileJobId, 90);

            $replyOptions = [];

            if (is_int($job['request_message_id'] ?? null)) {
                $replyOptions['reply_parameters'] = [
                    'message_id' => (int) $job['request_message_id'],
                    'allow_sending_without_reply' => true,
                ];
            }

            $caption = "✅ پردازش فایل #{$fileJobId} تکمیل شد.\n"
                . 'عملیات: ' . (string) $job['operation'];

            if (isset($result['caption']) && is_string($result['caption'])) {
                $caption .= "\n" . $result['caption'];
            }

            $remainingSeconds = max(
                1,
                (int) floor($deadline - microtime(true))
            );

            if ($remainingSeconds <= 1) {
                throw new FileToolException(
                    'زمان Job پیش از ارسال خروجی تمام شد.',
                    'job_timeout'
                );
            }

            $options = $replyOptions + [
                'caption' => mb_substr($caption, 0, 1000),
                'disable_content_type_detection' => false,
                '_timeout_seconds' => min(45, $remainingSeconds),
            ];

            if (($result['send_as_photo'] ?? false) === true) {
                unset($options['disable_content_type_detection']);
                $this->telegram->sendPhotoFile(
                    (int) $job['chat_id'],
                    (string) $result['path'],
                    (string) $result['name'],
                    $options
                );
            } else {
                $this->telegram->sendDocumentFile(
                    (int) $job['chat_id'],
                    (string) $result['path'],
                    (string) $result['name'],
                    $options
                );
            }

            $size = filesize((string) $result['path']);

            $this->jobs->complete(
                $fileJobId,
                (string) $result['name'],
                (string) $result['mime_type'],
                is_int($size) ? $size : 0
            );
        } catch (FileToolException $exception) {
            $this->handleFailure(
                $job,
                $exception,
                $exception->retryable
            );

            if ($exception->retryable) {
                throw $exception;
            }
        } catch (Throwable $exception) {
            $attempts = (int) ($job['attempts'] ?? 1);
            $maxAttempts = max(1, (int) ($job['max_attempts'] ?? 3));
            $retryable = $attempts < $maxAttempts;

            $wrapped = new FileToolException(
                'خطای موقت پردازش یا ارتباط با Telegram: '
                . $exception->getMessage(),
                'file_job_runtime_error',
                $retryable
            );

            $this->handleFailure(
                $job,
                $wrapped,
                $retryable
            );

            if ($retryable) {
                throw $wrapped;
            }
        } finally {
            $this->temporaryFiles->removeWorkspace(
                $workspace
            );
        }
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $parameters
     * @return array{path: string, name: string, mime_type: string, send_as_photo?: bool, caption?: string}
     */
    private function processTextJob(
        array $job,
        array $parameters,
        string $workspace,
        float $deadline
    ): array {
        $input = (string) ($job['input_text'] ?? '');
        $operation = (string) $job['operation'];

        $this->jobs->progress((int) $job['id'], 20);
        $this->assertDeadline($deadline);

        if ($operation === 'qr') {
            $output = $this->temporaryFiles->createFile(
                $workspace,
                'png'
            );

            $this->qrCodes->generate(
                $input,
                $output,
                (int) ($parameters['size'] ?? 700)
            );

            $this->assertOutputLimit($output);

            return [
                'path' => $output,
                'name' => 'qr-' . (int) $job['id'] . '.png',
                'mime_type' => 'image/png',
                'send_as_photo' => true,
            ];
        }

        $extension = match ($operation) {
            'tojson' => 'json',
            'tocsv' => 'csv',
            default => 'txt',
        };

        $output = $this->temporaryFiles->createFile(
            $workspace,
            $extension
        );

        $result = $this->textFiles->convert(
            $operation,
            $input,
            $output
        );

        $this->assertOutputLimit($output);

        return [
            'path' => $output,
            'name' => 'text-' . (int) $job['id'] . '.' . $result['extension'],
            'mime_type' => $result['mime_type'],
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $parameters
     * @return array{path: string, name: string, mime_type: string, caption?: string}
     */
    private function processTelegramFileJob(
        array $job,
        array $parameters,
        string $workspace,
        float $deadline
    ): array {
        $fileId = $job['file_id'] ?? null;

        if (!is_string($fileId) || $fileId === '') {
            throw new FileToolException(
                'file_id ورودی موجود نیست.',
                'file_id_missing'
            );
        }

        $extension = $this->safeExtension(
            (string) ($job['file_name'] ?? '')
        );

        $input = $this->temporaryFiles->createFile(
            $workspace,
            $extension !== '' ? $extension : 'bin'
        );

        $downloadTimeout = max(
            1,
            min(
                30,
                (int) floor($deadline - microtime(true))
            )
        );

        if ($downloadTimeout <= 1) {
            throw new FileToolException(
                'زمان Job پیش از دانلود فایل تمام شد.',
                'job_timeout'
            );
        }

        $this->telegram->downloadFile(
            $fileId,
            $input,
            min(10485760, max(1024, $this->maxFileBytes)),
            $downloadTimeout
        );

        $size = filesize($input);

        if (!is_int($size) || $size > min(10485760, max(1024, $this->maxFileBytes))) {
            throw new FileToolException(
                'حداکثر اندازه فایل 10MB است.',
                'file_size_limit'
            );
        }

        $this->jobs->progress((int) $job['id'], 35);
        $this->assertDeadline($deadline);

        $actualMime = $this->inspector->detectMime(
            $input,
            is_string($job['mime_type'] ?? null)
                ? $job['mime_type']
                : null
        );

        $operation = (string) $job['operation'];
        $baseName = $this->safeBaseName(
            (string) ($job['file_name'] ?? 'telegram-file')
        );

        if ($operation === 'fileinfo') {
            $info = $this->inspector->inspect(
                $input,
                $job + ['mime_type' => $actualMime],
                max(3, (int) ($deadline - microtime(true)))
            );

            $output = $this->temporaryFiles->createFile(
                $workspace,
                'json'
            );

            try {
                $json = json_encode(
                    $info,
                    JSON_THROW_ON_ERROR
                    | JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                );
            } catch (JsonException $exception) {
                throw new FileToolException(
                    'گزارش مشخصات فایل ساخته نشد.',
                    'fileinfo_encode_failed'
                );
            }

            file_put_contents($output, $json . PHP_EOL, LOCK_EX);

            return [
                'path' => $output,
                'name' => $baseName . '-info.json',
                'mime_type' => 'application/json',
                'caption' => 'نوع: ' . $actualMime
                    . ' · اندازه: ' . $this->humanBytes($size),
            ];
        }

        if ($operation === 'filehash') {
            $output = $this->temporaryFiles->createFile(
                $workspace,
                'txt'
            );

            $sha256 = hash_file('sha256', $input);
            $md5 = hash_file('md5', $input);

            file_put_contents(
                $output,
                "File: {$baseName}\n"
                . "Size: {$size} bytes\n"
                . "SHA-256: {$sha256}\n"
                . "MD5: {$md5}\n",
                LOCK_EX
            );

            return [
                'path' => $output,
                'name' => $baseName . '-hashes.txt',
                'mime_type' => 'text/plain',
                'caption' => 'SHA-256: ' . $sha256,
            ];
        }

        if ($operation === 'pdftext') {
            if ($actualMime !== 'application/pdf' && $extension !== 'pdf') {
                throw new FileToolException(
                    'فایل ورودی PDF نیست.',
                    'pdf_required'
                );
            }

            $output = $this->temporaryFiles->createFile(
                $workspace,
                'txt'
            );

            $pdfResult = $this->pdfText->extract(
                $input,
                $output,
                max(3, (int) ($deadline - microtime(true)))
            );

            return [
                'path' => $output,
                'name' => $baseName . '-text.txt',
                'mime_type' => 'text/plain',
                'caption' => 'صفحات: ' . $pdfResult['pages']
                    . ($pdfResult['truncated'] ? ' · خروجی کوتاه شده' : ''),
            ];
        }

        $imageDimensions = @getimagesize($input);

        if (!is_array($imageDimensions)) {
            throw new FileToolException(
                'فایل ورودی تصویر معتبر نیست.',
                'image_required'
            );
        }

        $width = (int) ($imageDimensions[0] ?? 0);
        $height = (int) ($imageDimensions[1] ?? 0);

        if ($width * $height > min(12000000, max(1, $this->maxImagePixels))) {
            throw new FileToolException(
                'حداکثر اندازه تصویر 12 مگاپیکسل است.',
                'image_pixel_limit'
            );
        }

        $targetExtension = match ($operation) {
            'towebp' => 'webp',
            'tojpeg' => 'jpg',
            default => $extension !== '' ? $extension : 'png',
        };

        if (!in_array($targetExtension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $targetExtension = 'png';
        }

        $output = $this->temporaryFiles->createFile(
            $workspace,
            $targetExtension
        );

        $imageResult = $this->images->process(
            $operation,
            $input,
            $output,
            $parameters,
            $deadline
        );

        $this->assertOutputLimit($output);

        return [
            'path' => $output,
            'name' => $baseName
                . '-'
                . $operation
                . '.'
                . $imageResult['extension'],
            'mime_type' => $imageResult['mime_type'],
            'caption' => $imageResult['width']
                . '×'
                . $imageResult['height']
                . ' · '
                . $this->humanBytes((int) filesize($output)),
        ];
    }

    /**
     * @param array<string, mixed> $job
     */
    private function handleFailure(
        array $job,
        FileToolException $exception,
        bool $retryable
    ): void {
        $id = (int) $job['id'];

        if ($retryable) {
            $this->jobs->requeue(
                $id,
                $exception->errorCode,
                $exception->getMessage()
            );
        } else {
            $this->jobs->fail(
                $id,
                $exception->errorCode,
                $exception->getMessage()
            );

            try {
                $options = [];

                if (is_int($job['request_message_id'] ?? null)) {
                    $options['reply_parameters'] = [
                        'message_id' => (int) $job['request_message_id'],
                        'allow_sending_without_reply' => true,
                    ];
                }

                $this->telegram->sendMessage(
                    (int) $job['chat_id'],
                    "پردازش فایل #{$id} ناموفق بود. ⚠️\n\n"
                    . $exception->getMessage(),
                    $options
                );
            } catch (Throwable) {
            }
        }

        $this->log($id, $exception);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeParameters(string $json): array
    {
        try {
            $value = json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new FileToolException(
                'پارامترهای Job نامعتبر است.',
                'job_parameters_invalid'
            );
        }

        return is_array($value) ? $value : [];
    }

    private function assertOutputLimit(string $path): void
    {
        $size = filesize($path);

        if (!is_int($size) || $size <= 0) {
            throw new FileToolException(
                'فایل خروجی خالی یا نامعتبر است.',
                'empty_output'
            );
        }

        if ($size > min(10485760, max(1024, $this->maxFileBytes))) {
            throw new FileToolException(
                'فایل خروجی بیش از سقف 10MB است.',
                'output_size_limit'
            );
        }
    }

    private function assertDeadline(float $deadline): void
    {
        if (microtime(true) > $deadline) {
            throw new FileToolException(
                'زمان پردازش فایل تمام شد.',
                'job_timeout'
            );
        }
    }

    private function safeExtension(string $fileName): string
    {
        $extension = mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?? '';

        return mb_substr($extension, 0, 12);
    }

    private function safeBaseName(string $fileName): string
    {
        $base = pathinfo(basename($fileName), PATHINFO_FILENAME);
        $base = preg_replace('/[^\pL\pN._-]+/u', '-', $base) ?? 'file';
        $base = trim($base, '-_.');

        return $base !== ''
            ? mb_substr($base, 0, 100)
            : 'file';
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }

    private function log(int $id, Throwable $exception): void
    {
        if ($this->logFile === '') {
            return;
        }

        $directory = dirname($this->logFile);

        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        @file_put_contents(
            $this->logFile,
            sprintf(
                "[%s] [file_job:%d] %s\n%s\n\n",
                date(DATE_ATOM),
                $id,
                $exception->getMessage(),
                $exception->getTraceAsString()
            ),
            FILE_APPEND | LOCK_EX
        );
    }
}
