<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\FileTools;

use JsonException;

final class TextFileProcessor
{
    public function __construct(
        private readonly int $maxOutputBytes = 512000
    ) {
    }

    /**
     * @return array{mime_type: string, extension: string, bytes: int}
     */
    public function convert(
        string $operation,
        string $input,
        string $outputPath
    ): array {
        return match ($operation) {
            'totxt' => $this->toTxt($input, $outputPath),
            'tojson' => $this->toJson($input, $outputPath),
            'tocsv' => $this->toCsv($input, $outputPath),
            default => throw new FileToolException(
                'عملیات تبدیل متن شناخته نشد.',
                'text_operation_unknown'
            ),
        };
    }

    /**
     * @return array{mime_type: string, extension: string, bytes: int}
     */
    private function toTxt(string $input, string $outputPath): array
    {
        $this->writeLimited($outputPath, $input);

        return [
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'bytes' => (int) filesize($outputPath),
        ];
    }

    /**
     * @return array{mime_type: string, extension: string, bytes: int}
     */
    private function toJson(string $input, string $outputPath): array
    {
        try {
            $decoded = json_decode(
                $input,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            $value = $decoded;
        } catch (JsonException) {
            $value = [
                'text' => $input,
            ];
        }

        try {
            $json = json_encode(
                $value,
                JSON_THROW_ON_ERROR
                | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new FileToolException(
                'JSON خروجی ساخته نشد: ' . $exception->getMessage(),
                'json_encode_failed'
            );
        }

        $this->writeLimited($outputPath, $json . PHP_EOL);

        return [
            'mime_type' => 'application/json',
            'extension' => 'json',
            'bytes' => (int) filesize($outputPath),
        ];
    }

    /**
     * @return array{mime_type: string, extension: string, bytes: int}
     */
    private function toCsv(string $input, string $outputPath): array
    {
        $handle = fopen($outputPath, 'wb');

        if ($handle === false) {
            throw new FileToolException(
                'فایل CSV ساخته نشد.',
                'csv_create_failed'
            );
        }

        try {
            fwrite($handle, "\xEF\xBB\xBF");
            $rows = $this->rows($input);

            foreach ($rows as $row) {
                if (fputcsv($handle, $row) === false) {
                    throw new FileToolException(
                        'نوشتن CSV ناموفق بود.',
                        'csv_write_failed'
                    );
                }

                $position = ftell($handle);

                if (
                    is_int($position)
                    && $position > min(512000, max(1024, $this->maxOutputBytes))
                ) {
                    throw new FileToolException(
                        'خروجی CSV بیش از سقف 500KB است.',
                        'text_output_limit'
                    );
                }
            }
        } finally {
            fclose($handle);
        }

        return [
            'mime_type' => 'text/csv',
            'extension' => 'csv',
            'bytes' => (int) filesize($outputPath),
        ];
    }

    /**
     * @return list<list<string>>
     */
    private function rows(string $input): array
    {
        try {
            $json = json_decode(
                $input,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (is_array($json)) {
                $rows = $this->rowsFromJson($json);

                if ($rows !== []) {
                    return $rows;
                }
            }
        } catch (JsonException) {
        }

        $lines = preg_split('/\R/u', trim($input));
        $lines = is_array($lines)
            ? array_values(array_filter(
                $lines,
                static fn (string $line): bool => trim($line) !== ''
            ))
            : [];

        if ($lines === []) {
            return [['text'], ['']];
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $rows = [];

        foreach ($lines as $line) {
            if ($delimiter === null) {
                $rows[] = [$line];
            } else {
                $parsed = str_getcsv($line, $delimiter);
                $rows[] = array_map(
                    static fn (mixed $value): string => (string) $value,
                    $parsed
                );
            }
        }

        return $rows;
    }

    /**
     * @param array<mixed> $json
     * @return list<list<string>>
     */
    private function rowsFromJson(array $json): array
    {
        if (!array_is_list($json)) {
            $json = [$json];
        }

        if ($json === []) {
            return [];
        }

        $headers = [];

        foreach ($json as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach (array_keys($row) as $key) {
                $key = (string) $key;

                if (!in_array($key, $headers, true)) {
                    $headers[] = $key;
                }
            }
        }

        if ($headers === []) {
            return array_map(
                static fn (mixed $value): array => [(string) $value],
                $json
            );
        }

        $rows = [$headers];

        foreach ($json as $row) {
            if (!is_array($row)) {
                $rows[] = [(string) $row];
                continue;
            }

            $values = [];

            foreach ($headers as $header) {
                $value = $row[$header] ?? '';

                if (is_array($value) || is_object($value)) {
                    try {
                        $value = json_encode(
                            $value,
                            JSON_THROW_ON_ERROR
                            | JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                        );
                    } catch (JsonException) {
                        $value = '';
                    }
                } elseif (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif ($value === null) {
                    $value = '';
                }

                $values[] = (string) $value;
            }

            $rows[] = $values;
        }

        return $rows;
    }

    private function detectDelimiter(string $line): ?string
    {
        $counts = [
            ',' => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
            ';' => substr_count($line, ';'),
            '|' => substr_count($line, '|'),
        ];

        arsort($counts);
        $delimiter = array_key_first($counts);

        return $delimiter !== null && $counts[$delimiter] > 0
            ? $delimiter
            : null;
    }

    private function writeLimited(string $path, string $contents): void
    {
        if (strlen($contents) > min(512000, max(1024, $this->maxOutputBytes))) {
            throw new FileToolException(
                'خروجی متن بیش از سقف 500KB است.',
                'text_output_limit'
            );
        }

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new FileToolException(
                'فایل خروجی ذخیره نشد.',
                'text_write_failed'
            );
        }
    }
}
