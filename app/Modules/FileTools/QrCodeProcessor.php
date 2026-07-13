<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\FileTools;

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Throwable;

final class QrCodeProcessor
{
    public function __construct(
        private readonly FileCapabilities $capabilities,
        private readonly int $maxTextLength = 1500,
        private readonly int $defaultSize = 700
    ) {
    }

    public function generate(
        string $text,
        string $outputPath,
        int $size = 0
    ): void {
        $text = trim($text);

        if ($text === '') {
            throw new FileToolException(
                'متن QR خالی است.',
                'qr_empty_text'
            );
        }

        if (mb_strlen($text) > max(50, $this->maxTextLength)) {
            throw new FileToolException(
                'متن QR بیش از حد طولانی است.',
                'qr_text_limit'
            );
        }

        if (!$this->capabilities->available('qr_png')) {
            throw new FileToolException(
                'ساخت QR روی این سرور فعال نیست؛ endroid/qr-code و ext-gd لازم‌اند.',
                'qr_unavailable'
            );
        }

        try {
            $qrCode = new QrCode(
                data: $text,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::Medium,
                size: max(250, min(1200, $size > 0 ? $size : $this->defaultSize)),
                margin: 20,
                roundBlockSizeMode: RoundBlockSizeMode::Margin,
                foregroundColor: new Color(0, 0, 0),
                backgroundColor: new Color(255, 255, 255)
            );

            $result = (new PngWriter())->write($qrCode);
            $result->saveToFile($outputPath);
        } catch (Throwable $exception) {
            throw new FileToolException(
                'ساخت QR ناموفق بود: ' . $exception->getMessage(),
                'qr_generation_failed'
            );
        }
    }
}
