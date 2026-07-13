<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\FileTools;

final class PdfTextExtractor
{
    public function __construct(
        private readonly FileCapabilities $capabilities,
        private readonly ProcessRunner $processRunner,
        private readonly FileInfoInspector $inspector,
        private readonly int $maxPages = 20,
        private readonly int $maxTextBytes = 512000
    ) {
    }

    /**
     * @return array{pages: int, bytes: int, truncated: bool}
     */
    public function extract(
        string $inputPath,
        string $outputPath,
        int $timeoutSeconds
    ): array {
        if (!$this->capabilities->available('pdf_text')) {
            throw new FileToolException(
                'استخراج متن PDF روی این سرور فعال نیست؛ pdftotext و pdfinfo لازم‌اند.',
                'pdf_text_unavailable'
            );
        }

        $pages = $this->inspector->pdfPages(
            $inputPath,
            max(1, intdiv($timeoutSeconds, 3))
        );

        if ($pages === null) {
            throw new FileToolException(
                'تعداد صفحات PDF قابل تشخیص نیست.',
                'pdf_pages_unknown'
            );
        }

        $pageLimit = min(20, max(1, $this->maxPages));

        if ($pages > $pageLimit) {
            throw new FileToolException(
                "PDF بیش از {$pageLimit} صفحه دارد.",
                'pdf_page_limit'
            );
        }

        $pdftotext = $this->capabilities->executable('pdftotext');

        if ($pdftotext === null) {
            throw new FileToolException(
                'pdftotext پیدا نشد.',
                'pdftotext_unavailable'
            );
        }

        $result = $this->processRunner->run(
            [
                $pdftotext,
                '-enc',
                'UTF-8',
                '-layout',
                '-f',
                '1',
                '-l',
                (string) $pages,
                $inputPath,
                $outputPath,
            ],
            max(1, $timeoutSeconds),
            262144
        );

        if ($result['exit_code'] !== 0 || !is_file($outputPath)) {
            throw new FileToolException(
                'متن PDF استخراج نشد؛ ممکن است PDF رمزگذاری‌شده یا تصویری باشد.',
                'pdftotext_failed'
            );
        }

        $size = filesize($outputPath);

        if (!is_int($size)) {
            throw new FileToolException(
                'اندازه متن استخراج‌شده خوانده نشد.',
                'pdf_text_size_unknown'
            );
        }

        $truncated = false;
        $limit = min(512000, max(1024, $this->maxTextBytes));

        if ($size > $limit) {
            $handle = fopen($outputPath, 'rb');

            if ($handle === false) {
                throw new FileToolException(
                    'متن استخراج‌شده قابل خواندن نیست.',
                    'pdf_text_read_failed'
                );
            }

            $content = fread($handle, $limit - 100);
            fclose($handle);

            if ($content === false) {
                throw new FileToolException(
                    'متن استخراج‌شده قابل خواندن نیست.',
                    'pdf_text_read_failed'
                );
            }

            while ($content !== '' && !mb_check_encoding($content, 'UTF-8')) {
                $content = substr($content, 0, -1);
            }

            $content .= "\n\n[خروجی به‌دلیل سقف 500KB کوتاه شد.]\n";
            file_put_contents($outputPath, $content, LOCK_EX);
            $size = strlen($content);
            $truncated = true;
        }

        return [
            'pages' => $pages,
            'bytes' => $size,
            'truncated' => $truncated,
        ];
    }
}
