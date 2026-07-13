<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Inline;

final class InlineResultFactory
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function article(
        string $namespace,
        string $key,
        string $title,
        string $description,
        string $message,
        array $options = []
    ): array {
        $result = [
            'type' => 'article',
            'id' => substr(
                hash(
                    'sha256',
                    $namespace . ':' . $key
                ),
                0,
                40
            ),
            'title' => mb_substr(
                trim($title),
                0,
                120
            ),
            'description' => mb_substr(
                trim($description),
                0,
                250
            ),
            'input_message_content' => [
                'message_text' => mb_substr(
                    trim($message),
                    0,
                    4000
                ),
                'disable_web_page_preview' => true,
            ],
        ];

        if (
            is_string(
                $options['thumbnail_url']
                ?? null
            )
            && filter_var(
                $options['thumbnail_url'],
                FILTER_VALIDATE_URL
            ) !== false
        ) {
            $result['thumbnail_url'] =
                $options['thumbnail_url'];
        }

        if (
            is_string($options['url'] ?? null)
            && filter_var(
                $options['url'],
                FILTER_VALIDATE_URL
            ) !== false
        ) {
            $result['url'] = $options['url'];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function error(
        string $namespace,
        string $message
    ): array {
        return $this->article(
            $namespace,
            $message,
            '⚠️ نتیجه‌ای پیدا نشد',
            $message,
            "⚠️ {$message}"
        );
    }
}
