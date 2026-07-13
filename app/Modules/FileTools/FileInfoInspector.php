<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\FileTools;

use Throwable;
use ZipArchive;

final class FileInfoInspector
{
    public function __construct(
        private readonly FileCapabilities $capabilities,
        private readonly ProcessRunner $processRunner,
        private readonly int $maxPdfPages = 20
    ) {
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public function inspect(string $path, array $job, int $timeoutSeconds): array
    {
        $size = filesize($path);

        if (!is_int($size)) {
            throw new FileToolException(
                'اندازه فایل خوانده نشد.',
                'file_size_unavailable'
            );
        }

        $mime = $this->detectMime(
            $path,
            is_string($job['mime_type'] ?? null)
                ? $job['mime_type']
                : null
        );

        $result = [
            'name' => (string) ($job['file_name'] ?? basename($path)),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'extension' => mb_strtolower(pathinfo(
                (string) ($job['file_name'] ?? $path),
                PATHINFO_EXTENSION
            )),
            'sha256' => hash_file('sha256', $path),
        ];

        $image = @getimagesize($path);

        if (is_array($image)) {
            $result['image'] = [
                'width' => (int) ($image[0] ?? 0),
                'height' => (int) ($image[1] ?? 0),
                'pixels' => (int) ($image[0] ?? 0) * (int) ($image[1] ?? 0),
                'type' => (int) ($image[2] ?? 0),
                'bits' => isset($image['bits']) ? (int) $image['bits'] : null,
                'channels' => isset($image['channels']) ? (int) $image['channels'] : null,
            ];
        }

        if ($mime === 'application/pdf') {
            $pages = $this->pdfPages($path, $timeoutSeconds);
            $result['pdf'] = [
                'pages' => $pages,
                'within_limit' => $pages !== null
                    ? $pages <= min(20, max(1, $this->maxPdfPages))
                    : null,
            ];
        }

        if (
            $this->capabilities->available('ext_zip')
            && $this->looksLikeZip($path, $mime, $result['extension'])
        ) {
            $result['zip'] = $this->zipInfo($path);
        }

        return $result;
    }

    public function detectMime(string $path, ?string $fallback = null): string
    {
        if ($this->capabilities->available('ext_fileinfo')) {
            try {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($path);

                if (is_string($mime) && trim($mime) !== '') {
                    return trim($mime);
                }
            } catch (Throwable) {
            }
        }

        return is_string($fallback) && trim($fallback) !== ''
            ? trim($fallback)
            : 'application/octet-stream';
    }

    public function pdfPages(string $path, int $timeoutSeconds): ?int
    {
        $pdfinfo = $this->capabilities->executable('pdfinfo');

        if ($pdfinfo === null) {
            return null;
        }

        $result = $this->processRunner->run(
            [$pdfinfo, $path],
            max(1, $timeoutSeconds),
            262144
        );

        if ($result['exit_code'] !== 0) {
            throw new FileToolException(
                'PDF معتبر نیست یا اطلاعات صفحات خوانده نشد.',
                'pdfinfo_failed'
            );
        }

        if (preg_match('/^Pages:\s*(\d+)\s*$/mi', $result['stdout'], $matches) !== 1) {
            throw new FileToolException(
                'تعداد صفحات PDF مشخص نشد.',
                'pdf_pages_unknown'
            );
        }

        return (int) $matches[1];
    }

    /**
     * @return array{entries: int, uncompressed_bytes: int, encrypted_entries: int}
     */
    private function zipInfo(string $path): array
    {
        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::RDONLY);

        if ($opened !== true) {
            throw new FileToolException(
                'فایل ZIP معتبر نیست.',
                'zip_open_failed'
            );
        }

        $entries = $zip->numFiles;
        $uncompressed = 0;
        $encrypted = 0;

        try {
            for ($index = 0; $index < $entries; $index++) {
                $stat = $zip->statIndex($index);

                if (!is_array($stat)) {
                    continue;
                }

                $uncompressed += (int) ($stat['size'] ?? 0);

                if (($stat['encryption_method'] ?? 0) !== 0) {
                    $encrypted++;
                }
            }
        } finally {
            $zip->close();
        }

        return [
            'entries' => $entries,
            'uncompressed_bytes' => $uncompressed,
            'encrypted_entries' => $encrypted,
        ];
    }

    private function looksLikeZip(string $path, string $mime, string $extension): bool
    {
        if (
            in_array(
                $mime,
                [
                    'application/zip',
                    'application/x-zip-compressed',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                ],
                true
            )
            || in_array($extension, ['zip', 'docx', 'xlsx', 'pptx'], true)
        ) {
            return true;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $signature = fread($handle, 4);
        fclose($handle);

        return in_array($signature, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
    }
}
