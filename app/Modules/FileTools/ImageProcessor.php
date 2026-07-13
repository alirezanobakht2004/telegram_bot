<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\FileTools;

use GdImage;
use Throwable;

final class ImageProcessor
{
    public function __construct(
        private readonly FileCapabilities $capabilities,
        private readonly int $maxPixels = 12000000,
        private readonly int $defaultQuality = 78
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{mime_type: string, width: int, height: int, extension: string}
     */
    public function process(
        string $operation,
        string $inputPath,
        string $outputPath,
        array $parameters,
        float $deadline
    ): array {
        if (!$this->capabilities->available('image_processing')) {
            throw new FileToolException(
                'پردازش تصویر روی این سرور فعال نیست؛ ext-gd یا Imagick لازم است.',
                'image_processing_unavailable'
            );
        }

        $dimensions = @getimagesize($inputPath);

        if (!is_array($dimensions)) {
            throw new FileToolException(
                'فایل ورودی تصویر معتبر نیست.',
                'invalid_image'
            );
        }

        $width = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);

        $this->assertPixels($width, $height);
        $this->assertDeadline($deadline);

        if ($this->capabilities->available('imagick')) {
            return $this->processWithImagick(
                $operation,
                $inputPath,
                $outputPath,
                $parameters,
                $deadline
            );
        }

        return $this->processWithGd(
            $operation,
            $inputPath,
            $outputPath,
            $parameters,
            $deadline
        );
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{mime_type: string, width: int, height: int, extension: string}
     */
    private function processWithImagick(
        string $operation,
        string $inputPath,
        string $outputPath,
        array $parameters,
        float $deadline
    ): array {
        try {
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 96 * 1024 * 1024);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 128 * 1024 * 1024);
        } catch (Throwable) {
        }

        $image = new \Imagick();

        try {
            $image->readImage($inputPath . '[0]');
            $image->setIteratorIndex(0);

            if (method_exists($image, 'autoOrient')) {
                $image->autoOrient();
            } elseif (method_exists($image, 'autoOrientImage')) {
                $image->autoOrientImage();
            }

            $image->stripImage();

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $this->assertPixels($width, $height);

            $quality = $this->quality($parameters);
            $format = mb_strtolower($image->getImageFormat());

            if ($operation === 'resize') {
                [$targetWidth, $targetHeight] = $this->targetDimensions(
                    $width,
                    $height,
                    $parameters
                );

                $image->thumbnailImage(
                    $targetWidth,
                    $targetHeight,
                    true,
                    true
                );
            }

            if ($operation === 'tojpeg') {
                $background = new \Imagick();
                $background->newImage(
                    $image->getImageWidth(),
                    $image->getImageHeight(),
                    new \ImagickPixel('white')
                );
                $background->setImageFormat('jpeg');
                $background->compositeImage(
                    $image,
                    \Imagick::COMPOSITE_OVER,
                    0,
                    0
                );
                $image->clear();
                $image = $background;
                $format = 'jpeg';
            } elseif ($operation === 'towebp') {
                $format = 'webp';
            } elseif ($operation === 'compress') {
                if (!in_array($format, ['jpeg', 'jpg', 'png', 'webp'], true)) {
                    $format = 'jpeg';
                }
            } elseif ($operation === 'removeexif' || $operation === 'resize') {
                if (!in_array($format, ['jpeg', 'jpg', 'png', 'webp'], true)) {
                    $format = 'png';
                }
            }

            $format = $format === 'jpg' ? 'jpeg' : $format;
            $image->setImageFormat($format);

            if (in_array($format, ['jpeg', 'webp'], true)) {
                $image->setImageCompressionQuality($quality);
            } elseif ($format === 'png') {
                $image->setOption(
                    'png:compression-level',
                    (string) $this->pngCompression($quality)
                );
            }

            $this->assertDeadline($deadline);

            if (!$image->writeImage($outputPath)) {
                throw new FileToolException(
                    'خروجی تصویر ذخیره نشد.',
                    'image_write_failed'
                );
            }

            return [
                'mime_type' => match ($format) {
                    'jpeg' => 'image/jpeg',
                    'webp' => 'image/webp',
                    default => 'image/png',
                },
                'width' => $image->getImageWidth(),
                'height' => $image->getImageHeight(),
                'extension' => $format === 'jpeg' ? 'jpg' : $format,
            ];
        } catch (FileToolException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new FileToolException(
                'پردازش تصویر با Imagick ناموفق بود: ' . $exception->getMessage(),
                'imagick_failed'
            );
        } finally {
            $image->clear();
            $image->destroy();
        }
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{mime_type: string, width: int, height: int, extension: string}
     */
    private function processWithGd(
        string $operation,
        string $inputPath,
        string $outputPath,
        array $parameters,
        float $deadline
    ): array {
        $contents = file_get_contents($inputPath);

        if ($contents === false) {
            throw new FileToolException(
                'تصویر قابل خواندن نیست.',
                'image_read_failed'
            );
        }

        $source = @imagecreatefromstring($contents);
        unset($contents);

        if (!$source instanceof GdImage) {
            throw new FileToolException(
                'فرمت تصویر توسط GD پشتیبانی نمی‌شود.',
                'gd_decode_failed'
            );
        }

        try {
            $source = $this->applyExifOrientation($source, $inputPath);
            $width = imagesx($source);
            $height = imagesy($source);
            $this->assertPixels($width, $height);

            if ($operation === 'resize') {
                [$targetWidth, $targetHeight] = $this->targetDimensions(
                    $width,
                    $height,
                    $parameters
                );

                $resized = imagecreatetruecolor($targetWidth, $targetHeight);

                if (!$resized instanceof GdImage) {
                    throw new FileToolException(
                        'حافظه کافی برای تغییر اندازه تصویر وجود ندارد.',
                        'image_memory_limit'
                    );
                }

                $this->prepareTransparency($resized);

                if (!imagecopyresampled(
                    $resized,
                    $source,
                    0,
                    0,
                    0,
                    0,
                    $targetWidth,
                    $targetHeight,
                    $width,
                    $height
                )) {
                    imagedestroy($resized);
                    throw new FileToolException(
                        'تغییر اندازه تصویر ناموفق بود.',
                        'image_resize_failed'
                    );
                }

                imagedestroy($source);
                $source = $resized;
                $width = $targetWidth;
                $height = $targetHeight;
            }

            $quality = $this->quality($parameters);
            $inputType = function_exists('exif_imagetype')
                ? @exif_imagetype($inputPath)
                : (int) ((@getimagesize($inputPath))[2] ?? 0);

            $extension = match ($operation) {
                'towebp' => 'webp',
                'tojpeg' => 'jpg',
                default => match ($inputType) {
                    IMAGETYPE_JPEG => 'jpg',
                    IMAGETYPE_WEBP => 'webp',
                    default => 'png',
                },
            };

            if (
                $extension === 'webp'
                && !function_exists('imagewebp')
                && $operation !== 'towebp'
            ) {
                $extension = 'png';
            }

            if ($operation === 'towebp' && !function_exists('imagewebp')) {
                throw new FileToolException(
                    'ساخت WebP در GD این سرور پشتیبانی نمی‌شود.',
                    'webp_unavailable'
                );
            }

            $this->assertDeadline($deadline);

            $written = match ($extension) {
                'jpg' => $this->writeJpeg($source, $outputPath, $quality),
                'webp' => imagewebp($source, $outputPath, $quality),
                default => imagepng(
                    $source,
                    $outputPath,
                    $this->pngCompression($quality)
                ),
            };

            if ($written !== true) {
                throw new FileToolException(
                    'خروجی تصویر ذخیره نشد.',
                    'image_write_failed'
                );
            }

            return [
                'mime_type' => match ($extension) {
                    'jpg' => 'image/jpeg',
                    'webp' => 'image/webp',
                    default => 'image/png',
                },
                'width' => $width,
                'height' => $height,
                'extension' => $extension,
            ];
        } finally {
            if ($source instanceof GdImage) {
                imagedestroy($source);
            }
        }
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{0: int, 1: int}
     */
    private function targetDimensions(
        int $width,
        int $height,
        array $parameters
    ): array {
        $maxWidth = max(1, (int) ($parameters['width'] ?? 0));
        $maxHeight = max(1, (int) ($parameters['height'] ?? $maxWidth));

        $scale = min(
            1.0,
            $maxWidth / max(1, $width),
            $maxHeight / max(1, $height)
        );

        return [
            max(1, (int) floor($width * $scale)),
            max(1, (int) floor($height * $scale)),
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function quality(array $parameters): int
    {
        return max(
            20,
            min(
                95,
                (int) ($parameters['quality'] ?? $this->defaultQuality)
            )
        );
    }

    private function pngCompression(int $quality): int
    {
        return max(0, min(9, (int) round((100 - $quality) / 11.111)));
    }

    private function assertPixels(int $width, int $height): void
    {
        if ($width <= 0 || $height <= 0) {
            throw new FileToolException(
                'ابعاد تصویر معتبر نیست.',
                'invalid_image_dimensions'
            );
        }

        if ($width * $height > min(12000000, max(1, $this->maxPixels))) {
            throw new FileToolException(
                'تصویر بیش از ۱۲ مگاپیکسل است.',
                'image_pixel_limit'
            );
        }
    }

    private function assertDeadline(float $deadline): void
    {
        if (microtime(true) > $deadline) {
            throw new FileToolException(
                'زمان پردازش تصویر تمام شد.',
                'job_timeout'
            );
        }
    }

    private function prepareTransparency(GdImage $image): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefilledrectangle(
            $image,
            0,
            0,
            imagesx($image),
            imagesy($image),
            $transparent
        );
    }

    private function writeJpeg(GdImage $source, string $outputPath, int $quality): bool
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);

        if (!$canvas instanceof GdImage) {
            return false;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        imagealphablending($canvas, true);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
        $result = imagejpeg($canvas, $outputPath, $quality);
        imagedestroy($canvas);

        return $result;
    }

    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        try {
            $data = @exif_read_data($path);
            $orientation = is_array($data)
                ? (int) ($data['Orientation'] ?? 1)
                : 1;

            $rotated = match ($orientation) {
                3 => imagerotate($image, 180, 0),
                6 => imagerotate($image, -90, 0),
                8 => imagerotate($image, 90, 0),
                default => false,
            };

            if ($rotated instanceof GdImage) {
                imagedestroy($image);
                return $rotated;
            }
        } catch (Throwable) {
        }

        return $image;
    }
}
