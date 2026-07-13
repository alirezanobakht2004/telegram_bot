<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\FileTools;

use PDO;
use Throwable;

final class FileCapabilities
{
    /**
     * @var array<string, array{available: bool, version: ?string, details: string}>
     */
    private array $capabilities;

    public function __construct(
        private readonly ?PDO $pdo = null,
        private readonly ?string $pdftotextPath = null,
        private readonly ?string $pdfinfoPath = null
    ) {
        $this->capabilities = $this->detect();
    }

    /**
     * @return array<string, array{available: bool, version: ?string, details: string}>
     */
    public function all(): array
    {
        return $this->capabilities;
    }

    public function available(string $name): bool
    {
        return ($this->capabilities[$name]['available'] ?? false) === true;
    }

    public function executable(string $name): ?string
    {
        $details = $this->capabilities[$name]['details'] ?? '';

        if (!str_starts_with($details, 'path=')) {
            return null;
        }

        return substr($details, 5) ?: null;
    }

    public function saveSnapshot(): void
    {
        if ($this->pdo === null) {
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO file_capability_snapshots (
                capability,
                available,
                version,
                details,
                checked_at
             ) VALUES (
                :capability,
                :available,
                :version,
                :details,
                :checked_at
             )'
        );

        $now = date(DATE_ATOM);

        foreach ($this->capabilities as $name => $row) {
            $statement->execute([
                'capability' => $name,
                'available' => $row['available'] ? 1 : 0,
                'version' => $row['version'],
                'details' => mb_substr($row['details'], 0, 1000),
                'checked_at' => $now,
            ]);
        }
    }

    /**
     * @return array<string, array{available: bool, version: ?string, details: string}>
     */
    private function detect(): array
    {
        $imagickAvailable = extension_loaded('imagick')
            && class_exists(\Imagick::class);

        $gdAvailable = extension_loaded('gd')
            && function_exists('imagecreatefromstring');

        $zipAvailable = extension_loaded('zip')
            && class_exists(\ZipArchive::class);

        $fileinfoAvailable = extension_loaded('fileinfo')
            && class_exists(\finfo::class);

        $qrAvailable = class_exists(\Endroid\QrCode\QrCode::class)
            && class_exists(\Endroid\QrCode\Writer\PngWriter::class)
            && $gdAvailable;

        $pdftotext = $this->resolveExecutable(
            $this->pdftotextPath,
            'pdftotext'
        );

        $pdfinfo = $this->resolveExecutable(
            $this->pdfinfoPath,
            'pdfinfo'
        );

        $imagickVersion = null;

        if ($imagickAvailable) {
            try {
                $version = \Imagick::getVersion();
                $imagickVersion = is_array($version)
                    ? (string) ($version['versionString'] ?? '')
                    : null;
            } catch (Throwable) {
            }
        }

        $gdVersion = $gdAvailable
            ? (string) (gd_info()['GD Version'] ?? PHP_VERSION)
            : null;

        return [
            'ext_gd' => [
                'available' => $gdAvailable,
                'version' => $gdVersion,
                'details' => $gdAvailable
                    ? 'JPEG=' . (function_exists('imagejpeg') ? 'yes' : 'no')
                        . '; WebP=' . (function_exists('imagewebp') ? 'yes' : 'no')
                    : 'PHP GD extension is not loaded.',
            ],
            'ext_fileinfo' => [
                'available' => $fileinfoAvailable,
                'version' => $fileinfoAvailable ? PHP_VERSION : null,
                'details' => $fileinfoAvailable
                    ? 'MIME detection enabled.'
                    : 'MIME detection falls back to Telegram metadata.',
            ],
            'ext_zip' => [
                'available' => $zipAvailable,
                'version' => $zipAvailable
                    ? (string) (phpversion('zip') ?: PHP_VERSION)
                    : null,
                'details' => $zipAvailable
                    ? 'ZIP metadata inspection enabled.'
                    : 'ZIP metadata inspection disabled.',
            ],
            'imagick' => [
                'available' => $imagickAvailable,
                'version' => $imagickVersion,
                'details' => $imagickAvailable
                    ? 'Preferred image engine.'
                    : 'Optional image engine is unavailable.',
            ],
            'image_processing' => [
                'available' => $imagickAvailable || $gdAvailable,
                'version' => $imagickAvailable
                    ? $imagickVersion
                    : $gdVersion,
                'details' => $imagickAvailable
                    ? 'engine=imagick'
                    : ($gdAvailable ? 'engine=gd' : 'No image engine available.'),
            ],
            'qr_png' => [
                'available' => $qrAvailable,
                'version' => $qrAvailable ? 'endroid/qr-code 6.x' : null,
                'details' => $qrAvailable
                    ? 'Endroid QR Code with PNG/GD writer.'
                    : 'Requires endroid/qr-code and ext-gd.',
            ],
            'pdftotext' => [
                'available' => $pdftotext !== null,
                'version' => null,
                'details' => $pdftotext !== null
                    ? 'path=' . $pdftotext
                    : 'pdftotext executable was not found.',
            ],
            'pdfinfo' => [
                'available' => $pdfinfo !== null,
                'version' => null,
                'details' => $pdfinfo !== null
                    ? 'path=' . $pdfinfo
                    : 'pdfinfo executable was not found.',
            ],
            'pdf_text' => [
                'available' => $pdftotext !== null && $pdfinfo !== null,
                'version' => null,
                'details' => $pdftotext !== null && $pdfinfo !== null
                    ? 'Page limit and extraction are enforceable.'
                    : 'Both pdftotext and pdfinfo are required.',
            ],
            'proc_open' => [
                'available' => function_exists('proc_open'),
                'version' => PHP_VERSION,
                'details' => function_exists('proc_open')
                    ? 'External process timeouts enabled.'
                    : 'External PDF tools cannot run.',
            ],
        ];
    }

    private function resolveExecutable(
        ?string $configuredPath,
        string $name
    ): ?string {
        if (
            is_string($configuredPath)
            && trim($configuredPath) !== ''
            && is_file($configuredPath)
            && is_executable($configuredPath)
        ) {
            return realpath($configuredPath) ?: $configuredPath;
        }

        if (!function_exists('proc_open')) {
            return null;
        }

        $command = DIRECTORY_SEPARATOR === '\\'
            ? ['where', $name]
            : ['command', '-v', $name];

        $options = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        try {
            if (DIRECTORY_SEPARATOR === '\\') {
                $process = proc_open(
                    $command,
                    $options,
                    $pipes,
                    null,
                    null,
                    ['bypass_shell' => true]
                );
            } else {
                $process = proc_open(
                    ['/bin/sh', '-lc', 'command -v ' . escapeshellarg($name)],
                    $options,
                    $pipes
                );
            }

            if (!is_resource($process)) {
                return null;
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            if ($status !== 0) {
                return null;
            }

            $line = trim((string) $stdout);

            if ($line === '') {
                return null;
            }

            $firstLine = preg_split('/\R/', $line)[0] ?? '';
            $resolved = trim($firstLine);

            return $resolved !== '' ? $resolved : null;
        } catch (Throwable) {
            return null;
        }
    }
}
