<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use JsonException;
use RuntimeException;

final readonly class HttpResponse
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200
            && $this->statusCode < 300;
    }

    public function requireSuccess(): self
    {
        if ($this->isSuccessful()) {
            return $this;
        }

        $preview = trim(mb_substr($this->body, 0, 300));

        throw new RuntimeException(
            sprintf(
                'HTTP request failed with status %d%s',
                $this->statusCode,
                $preview !== '' ? ': ' . $preview : ''
            )
        );
    }

    public function json(): mixed
    {
        try {
            return json_decode(
                $this->body,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'HTTP response does not contain valid JSON.',
                previous: $exception
            );
        }
    }

    /**
     * @return array<mixed>
     */
    public function jsonArray(): array
    {
        $data = $this->json();

        if (!is_array($data)) {
            throw new RuntimeException(
                'HTTP JSON response must be an array or object.'
            );
        }

        return $data;
    }

    public function firstHeader(string $name): ?string
    {
        $values = $this->headers[mb_strtolower($name)] ?? [];

        return $values[0] ?? null;
    }
}
