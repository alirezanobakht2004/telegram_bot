<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use RuntimeException;

final class HttpClient
{
    public function __construct(
        private readonly string $userAgent,
        private readonly int $connectTimeout = 4,
        private readonly int $timeout = 8,
        private readonly int $maxResponseBytes = 1048576
    ) {
        if ($this->connectTimeout < 1) {
            throw new RuntimeException(
                'HTTP connect timeout must be at least one second.'
            );
        }

        if ($this->timeout < $this->connectTimeout) {
            throw new RuntimeException(
                'HTTP timeout cannot be lower than connect timeout.'
            );
        }

        if ($this->maxResponseBytes < 1024) {
            throw new RuntimeException(
                'HTTP maximum response size is too small.'
            );
        }
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(
        string $url,
        array $headers = []
    ): HttpResponse {
        $this->assertSafeUrl($url);

        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException(
                'Could not initialize cURL.'
            );
        }

        $responseBody = '';
        $responseHeaders = [];
        $responseTooLarge = false;

        $requestHeaders = [
            'Accept' => 'application/json',
        ];

        foreach ($headers as $name => $value) {
            $requestHeaders[$name] = $value;
        }

        $formattedHeaders = [];

        foreach ($requestHeaders as $name => $value) {
            $this->assertSafeHeader($name, $value);
            $formattedHeaders[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function (
                mixed $curl,
                string $headerLine
            ) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $line = trim($headerLine);

                if ($line === '') {
                    return $length;
                }

                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];

                    return $length;
                }

                $separatorPosition = strpos($line, ':');

                if ($separatorPosition === false) {
                    return $length;
                }

                $name = mb_strtolower(
                    trim(substr($line, 0, $separatorPosition))
                );
                $value = trim(
                    substr($line, $separatorPosition + 1)
                );

                $responseHeaders[$name] ??= [];
                $responseHeaders[$name][] = $value;

                return $length;
            },
            CURLOPT_WRITEFUNCTION => function (
                mixed $curl,
                string $chunk
            ) use (
                &$responseBody,
                &$responseTooLarge
            ): int {
                $chunkLength = strlen($chunk);

                if (
                    strlen($responseBody) + $chunkLength
                    > $this->maxResponseBytes
                ) {
                    $responseTooLarge = true;

                    return 0;
                }

                $responseBody .= $chunk;

                return $chunkLength;
            },
        ];

        if (!curl_setopt_array($handle, $options)) {
            curl_close($handle);

            throw new RuntimeException(
                'Could not configure cURL request.'
            );
        }

        $executed = curl_exec($handle);
        $curlError = curl_error($handle);
        $statusCode = (int) curl_getinfo(
            $handle,
            CURLINFO_HTTP_CODE
        );

        curl_close($handle);

        if ($responseTooLarge) {
            throw new RuntimeException(
                'HTTP response exceeded the configured size limit.'
            );
        }

        if ($executed === false) {
            throw new RuntimeException(
                'HTTP request failed: ' . $curlError
            );
        }

        return new HttpResponse(
            statusCode: $statusCode,
            headers: $responseHeaders,
            body: $responseBody
        );
    }

    private function assertSafeUrl(string $url): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException(
                'HTTP request URL is invalid.'
            );
        }

        $scheme = mb_strtolower(
            (string) parse_url($url, PHP_URL_SCHEME)
        );

        if ($scheme !== 'https') {
            throw new RuntimeException(
                'Only HTTPS URLs are allowed.'
            );
        }
    }

    private function assertSafeHeader(
        string $name,
        string $value
    ): void {
        if (
            $name === ''
            || preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1
        ) {
            throw new RuntimeException(
                'HTTP header name is invalid.'
            );
        }

        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new RuntimeException(
                'HTTP header value is invalid.'
            );
        }
    }
}
