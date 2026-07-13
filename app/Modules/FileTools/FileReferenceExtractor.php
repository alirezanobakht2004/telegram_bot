<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\FileTools;

final class FileReferenceExtractor
{
    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    public function extract(array $message): ?array
    {
        $candidate = $this->extractFromSingleMessage($message);

        if ($candidate !== null) {
            return $candidate;
        }

        $reply = $message['reply_to_message'] ?? null;

        return is_array($reply)
            ? $this->extractFromSingleMessage($reply)
            : null;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    private function extractFromSingleMessage(array $message): ?array
    {
        $document = $message['document'] ?? null;

        if (is_array($document)) {
            return $this->normalize(
                $document,
                'document',
                $document['file_name'] ?? null,
                $document['mime_type'] ?? null,
                $document['thumbnail']['width'] ?? null,
                $document['thumbnail']['height'] ?? null
            );
        }

        $photos = $message['photo'] ?? null;

        if (is_array($photos) && $photos !== []) {
            $photo = end($photos);

            if (is_array($photo)) {
                return $this->normalize(
                    $photo,
                    'photo',
                    'telegram-photo.jpg',
                    'image/jpeg',
                    $photo['width'] ?? null,
                    $photo['height'] ?? null
                );
            }
        }

        foreach (
            [
                'audio' => 'audio',
                'video' => 'video',
                'voice' => 'voice',
                'animation' => 'animation',
                'video_note' => 'video_note',
                'sticker' => 'sticker',
            ]
            as $key => $kind
        ) {
            $value = $message[$key] ?? null;

            if (!is_array($value)) {
                continue;
            }

            $defaultName = $kind . '-' . ($value['file_unique_id'] ?? 'file');
            $extension = match ($kind) {
                'voice' => '.ogg',
                'video_note' => '.mp4',
                'sticker' => ($value['is_animated'] ?? false)
                    ? '.tgs'
                    : (($value['is_video'] ?? false) ? '.webm' : '.webp'),
                default => '',
            };

            return $this->normalize(
                $value,
                $kind,
                $value['file_name'] ?? ($defaultName . $extension),
                $value['mime_type'] ?? null,
                $value['width'] ?? null,
                $value['height'] ?? null
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>|null
     */
    private function normalize(
        array $value,
        string $kind,
        mixed $fileName,
        mixed $mimeType,
        mixed $width,
        mixed $height
    ): ?array {
        $fileId = $value['file_id'] ?? null;

        if (!is_string($fileId) || $fileId === '') {
            return null;
        }

        return [
            'kind' => $kind,
            'file_id' => $fileId,
            'file_unique_id' => is_string($value['file_unique_id'] ?? null)
                ? $value['file_unique_id']
                : null,
            'file_name' => is_string($fileName) && trim($fileName) !== ''
                ? trim($fileName)
                : ($kind . '-file'),
            'mime_type' => is_string($mimeType) && trim($mimeType) !== ''
                ? trim($mimeType)
                : null,
            'file_size' => is_numeric($value['file_size'] ?? null)
                ? (int) $value['file_size']
                : null,
            'width' => is_numeric($width) ? (int) $width : null,
            'height' => is_numeric($height) ? (int) $height : null,
        ];
    }
}
