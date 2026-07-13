<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\FileTools;

use InvalidArgumentException;
use SmartToolbox\Core\CommandRouter;
use SmartToolbox\Core\MessageContext;
use SmartToolbox\Core\ModuleInterface;
use SmartToolbox\Core\RateLimiter;
use Throwable;

final class FileToolsModule implements ModuleInterface
{
    public function __construct(
        private readonly FileJobRepository $jobs,
        private readonly FileReferenceExtractor $files,
        private readonly FileCapabilities $capabilities,
        private readonly RateLimiter $rateLimiter,
        private readonly int $maxFileBytes = 10485760,
        private readonly int $maxImagePixels = 12000000,
        private readonly int $maxTextBytes = 512000,
        private readonly int $maxQrTextLength = 1500,
        private readonly int $maxAttempts = 30,
        private readonly int $windowSeconds = 60
    ) {
    }

    public function register(CommandRouter $router): void
    {
        foreach (
            [
                'qr' => 'queueQr',
                'fileinfo' => 'queueFileInfo',
                'filehash' => 'queueFileHash',
                'removeexif' => 'queueImage',
                'resize' => 'queueImage',
                'compress' => 'queueImage',
                'towebp' => 'queueImage',
                'tojpeg' => 'queueImage',
                'pdftext' => 'queuePdfText',
                'totxt' => 'queueText',
                'tojson' => 'queueText',
                'tocsv' => 'queueText',
                'filejobs' => 'showJobs',
                'filecancel' => 'cancelJob',
                'filecapabilities' => 'showCapabilities',
            ]
            as $command => $method
        ) {
            $router->command(
                $command,
                function (
                    MessageContext $context,
                    string $arguments
                ) use ($method, $command): void {
                    $this->execute(
                        $context,
                        $method,
                        $command,
                        $arguments
                    );
                },
                'file_tools'
            );
        }

        $router->text(
            '📁 فایل و تصویر',
            function (MessageContext $context): void {
                $this->help($context);
            },
            'file_tools'
        );
    }

    private function execute(
        MessageContext $context,
        string $method,
        string $command,
        string $arguments
    ): void {
        if (!$this->allow($context)) {
            return;
        }

        if ($context->userId === null) {
            $context->reply(
                'این ابزار به شناسه کاربر نیاز دارد.'
            );
            return;
        }

        try {
            $this->{$method}(
                $context,
                $command,
                trim($arguments)
            );
        } catch (
            FileToolException
            | InvalidArgumentException $exception
        ) {
            $context->reply(
                "درخواست فایل ثبت نشد. ⚠️\n\n"
                . $exception->getMessage()
            );
        } catch (Throwable $exception) {
            $context->reply(
                'ثبت پردازش فایل با خطا مواجه شد.'
            );
        }
    }

    private function queueQr(
        MessageContext $context,
        string $command,
        string $arguments
    ): void {
        if (!$this->capabilities->available('qr_png')) {
            throw new FileToolException(
                'ساخت QR روی سرور فعال نیست؛ ext-gd و کتابخانه QR لازم‌اند.',
                'qr_unavailable'
            );
        }

        $text = $this->textSource(
            $context,
            $arguments
        );

        if (mb_strlen($text) > max(50, $this->maxQrTextLength)) {
            throw new FileToolException(
                'متن QR بیش از حد طولانی است.',
                'qr_text_limit'
            );
        }

        $size = 700;

        if (
            preg_match(
                '/^(\d{3,4})\s+(.+)$/us',
                $text,
                $matches
            ) === 1
            && (int) $matches[1] >= 250
            && (int) $matches[1] <= 1200
        ) {
            $size = (int) $matches[1];
            $text = trim($matches[2]);
        }

        $id = $this->jobs->create(
            userId: (int) $context->userId,
            chatId: $context->chatId,
            requestMessageId: $context->messageId,
            operation: 'qr',
            sourceKind: 'text',
            source: ['input_text' => $text],
            parameters: ['size' => $size]
        );

        $this->queued($context, $id, 'ساخت QR');
    }

    private function queueFileInfo(
        MessageContext $context,
        string $command,
        string $arguments
    ): void {
        $this->queueTelegramFile(
            $context,
            'fileinfo',
            []
        );
    }

    private function queueFileHash(
        MessageContext $context,
        string $command,
        string $arguments
    ): void {
        $this->queueTelegramFile(
            $context,
            'filehash',
            []
        );
    }

    private function queueImage(
        MessageContext $context,
        string $command,
        string $arguments
    ): void {
        if (!$this->capabilities->available('image_processing')) {
            throw new FileToolException(
                'پردازش تصویر روی سرور فعال نیست؛ ext-gd یا Imagick لازم است.',
                'image_processing_unavailable'
            );
        }

        $parameters = [];

        if ($command === 'resize') {
            $parameters = $this->resizeParameters($arguments);
        } elseif ($command === 'compress') {
            $quality = $arguments === ''
                ? 75
                : (int) $this->normalizeDigits($arguments);

            if ($quality < 20 || $quality > 95) {
                throw new FileToolException(
                    'کیفیت Compress باید بین 20 و 95 باشد.',
                    'invalid_quality'
                );
            }

            $parameters['quality'] = $quality;
        }

        $this->queueTelegramFile(
            $context,
            $command,
            $parameters,
            true
        );
    }

    private function queuePdfText(
        MessageContext $context,
        string $command,
        string $arguments
    ): void {
        if (!$this->capabilities->available('pdf_text')) {
            throw new FileToolException(
                'استخراج متن PDF غیرفعال است؛ pdftotext و pdfinfo روی سرور لازم‌اند.',
                'pdf_text_unavailable'
            );
        }

        $this->queueTelegramFile(
            $context,
            'pdftext',
            [],
            false,
            true
        );
    }

    private function queueText(
        MessageContext $context,
        string $command,
        string $arguments
    ): void {
        $text = $this->textSource(
            $context,
            $arguments
        );

        if (strlen($text) > min(512000, max(1024, $this->maxTextBytes))) {
            throw new FileToolException(
                'متن ورودی بیش از سقف 500KB است.',
                'text_input_limit'
            );
        }

        $id = $this->jobs->create(
            userId: (int) $context->userId,
            chatId: $context->chatId,
            requestMessageId: $context->messageId,
            operation: $command,
            sourceKind: 'text',
            source: ['input_text' => $text]
        );

        $this->queued(
            $context,
            $id,
            'تبدیل متن به ' . mb_strtoupper(substr($command, 2))
        );
    }

    private function showJobs(
        MessageContext $context,
        string $command,
        string $arguments
    ): void {
        $rows = $this->jobs->history(
            (int) $context->userId,
            10
        );

        if ($rows === []) {
            $context->reply(
                'هنوز پردازش فایلی ثبت نکرده‌ای.'
            );
            return;
        }

        $lines = ['📁 آخرین پردازش‌های فایل', ''];

        foreach ($rows as $row) {
            $line = '#'
                . (int) $row['id']
                . ' · '
                . (string) $row['operation']
                . ' · '
                . (string) $row['status'];

            if ($row['status'] === 'processing') {
                $line .= ' · ' . (int) $row['progress'] . '%';
            }

            $lines[] = $line;

            if (
                $row['status'] === 'failed'
                && is_string($row['error_message'])
            ) {
                $lines[] = 'خطا: ' . $row['error_message'];
            }

            if ($row['status'] === 'queued') {
                $lines[] = '/filecancel ' . (int) $row['id'];
            }

            $lines[] = '';
        }

        $context->reply(
            mb_substr(implode("\n", $lines), 0, 3900)
        );
    }

    private function cancelJob(
        MessageContext $context,
        string $command,
        string $arguments
    ): void {
        $id = (int) $this->normalizeDigits(trim($arguments));

        if ($id <= 0) {
            throw new FileToolException(
                'نمونه: /filecancel 12',
                'invalid_job_id'
            );
        }

        $context->reply(
            $this->jobs->cancel(
                $id,
                (int) $context->userId
            )
                ? "پردازش #{$id} لغو شد. ✅"
                : 'فقط پردازش Queued متعلق به خودت قابل لغو است.'
        );
    }

    private function showCapabilities(
        MessageContext $context,
        string $command,
        string $arguments
    ): void {
        $labels = [
            'ext_gd' => 'GD',
            'ext_fileinfo' => 'Fileinfo',
            'ext_zip' => 'ZIP',
            'imagick' => 'Imagick',
            'image_processing' => 'پردازش تصویر',
            'qr_png' => 'QR PNG',
            'pdftotext' => 'pdftotext',
            'pdfinfo' => 'pdfinfo',
            'pdf_text' => 'استخراج PDF',
            'proc_open' => 'Process Runner',
        ];

        $lines = ['🧩 قابلیت‌های فایل سرور', ''];

        foreach ($labels as $key => $label) {
            $row = $this->capabilities->all()[$key] ?? null;

            if (!is_array($row)) {
                continue;
            }

            $lines[] = ($row['available'] ? '✅ ' : '❌ ')
                . $label
                . ($row['version'] ? ' · ' . $row['version'] : '');
        }

        $context->reply(implode("\n", $lines));
    }

    private function help(MessageContext $context): void
    {
        $context->reply(
            "📁 ابزارهای فایل و تصویر\n\n"
            . "/qr متن — ساخت QR\n"
            . "/fileinfo — مشخصات فایل\n"
            . "/filehash — SHA-256 و MD5\n"
            . "/removeexif — حذف Metadata تصویر\n"
            . "/resize 800 یا 800x600\n"
            . "/compress 75\n"
            . "/towebp و /tojpeg\n"
            . "/pdftext — استخراج متن PDF\n"
            . "/totxt، /tojson، /tocsv\n\n"
            . "برای ابزارهای فایل روی فایل Reply کن یا دستور را Caption همان فایل قرار بده.\n"
            . "/filejobs — وضعیت پردازش‌ها\n"
            . "/filecapabilities — قابلیت‌های سرور"
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function queueTelegramFile(
        MessageContext $context,
        string $operation,
        array $parameters,
        bool $requireImage = false,
        bool $requirePdf = false
    ): void {
        $payload = $context->updateContext?->payload();

        if (!is_array($payload)) {
            throw new FileToolException(
                'پیام Telegram در دسترس نیست.',
                'message_payload_missing'
            );
        }

        $file = $this->files->extract($payload);

        if ($file === null) {
            throw new FileToolException(
                'روی فایل یا تصویر Reply کن، یا دستور را در Caption فایل بنویس.',
                'file_required'
            );
        }

        $size = $file['file_size'] ?? null;

        if (is_int($size) && $size > min(10485760, max(1024, $this->maxFileBytes))) {
            throw new FileToolException(
                'حداکثر اندازه فایل 10MB است.',
                'file_size_limit'
            );
        }

        $width = $file['width'] ?? null;
        $height = $file['height'] ?? null;

        if (
            is_int($width)
            && is_int($height)
            && $width * $height > min(12000000, max(1, $this->maxImagePixels))
        ) {
            throw new FileToolException(
                'حداکثر اندازه تصویر 12 مگاپیکسل است.',
                'image_pixel_limit'
            );
        }

        $mime = mb_strtolower((string) ($file['mime_type'] ?? ''));
        $extension = mb_strtolower(pathinfo(
            (string) ($file['file_name'] ?? ''),
            PATHINFO_EXTENSION
        ));

        if (
            $requireImage
            && !str_starts_with($mime, 'image/')
            && !in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'], true)
            && ($file['kind'] ?? '') !== 'photo'
        ) {
            throw new FileToolException(
                'این دستور فقط برای تصویر است.',
                'image_required'
            );
        }

        if (
            $requirePdf
            && $mime !== 'application/pdf'
            && $extension !== 'pdf'
        ) {
            throw new FileToolException(
                'این دستور فقط برای فایل PDF است.',
                'pdf_required'
            );
        }

        $id = $this->jobs->create(
            userId: (int) $context->userId,
            chatId: $context->chatId,
            requestMessageId: $context->messageId,
            operation: $operation,
            sourceKind: 'telegram_file',
            source: $file,
            parameters: $parameters
        );

        $this->queued($context, $id, $operation);
    }

    private function textSource(
        MessageContext $context,
        string $arguments
    ): string {
        if (trim($arguments) !== '') {
            return trim($arguments);
        }

        $payload = $context->updateContext?->payload();
        $reply = is_array($payload)
            ? ($payload['reply_to_message'] ?? null)
            : null;

        if (is_array($reply)) {
            $text = $reply['text'] ?? $reply['caption'] ?? null;

            if (is_string($text) && trim($text) !== '') {
                return trim($text);
            }
        }

        throw new FileToolException(
            'متن را بعد از دستور بنویس یا روی یک پیام متنی Reply کن.',
            'text_required'
        );
    }

    /**
     * @return array{width: int, height: int}
     */
    private function resizeParameters(string $arguments): array
    {
        $value = $this->normalizeDigits(trim($arguments));

        if (preg_match('/^(\d{2,5})(?:x(\d{2,5}))?$/i', $value, $matches) !== 1) {
            throw new FileToolException(
                'فرمت Resize: /resize 800 یا /resize 800x600',
                'invalid_resize'
            );
        }

        $width = (int) $matches[1];
        $height = isset($matches[2]) && $matches[2] !== ''
            ? (int) $matches[2]
            : $width;

        if ($width < 32 || $height < 32 || $width > 8000 || $height > 8000) {
            throw new FileToolException(
                'ابعاد Resize باید بین 32 و 8000 پیکسل باشد.',
                'invalid_resize'
            );
        }

        return [
            'width' => $width,
            'height' => $height,
        ];
    }

    private function queued(
        MessageContext $context,
        int $id,
        string $label
    ): void {
        $context->reply(
            "پردازش #{$id} در صف قرار گرفت. ⏳\n\n"
            . "عملیات: {$label}\n"
            . "نتیجه پس از اجرای Worker ارسال می‌شود.\n"
            . "/filejobs"
        );
    }

    private function allow(MessageContext $context): bool
    {
        $result = $this->rateLimiter->attempt(
            'file-tools:' . $context->actorKey(),
            max(1, $this->maxAttempts),
            max(1, $this->windowSeconds)
        );

        if ($result->allowed) {
            return true;
        }

        $context->reply(
            "درخواست‌های فایل زیاد است؛ {$result->retryAfter} ثانیه دیگر تلاش کن."
        );

        return false;
    }

    private function normalizeDigits(string $value): string
    {
        return strtr(
            $value,
            [
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                '×' => 'x',
            ]
        );
    }
}
