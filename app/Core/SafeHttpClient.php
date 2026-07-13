<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use RuntimeException;
use Throwable;

final class SafeHttpClient
{
    public function __construct(
        private readonly string $userAgent,
        private readonly SsrfGuard $ssrfGuard,
        private readonly int $connectTimeout = 4,
        private readonly int $timeout = 8,
        private readonly int $maxResponseBytes = 1048576,
        private readonly int $maxRedirects = 3,
        private readonly ?ApiMetricsTracker $metrics = null
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
        $currentUrl = trim($url);
        $redirects = 0;

        while (true) {
            $inspection = $this->ssrfGuard->inspect(
                $currentUrl
            );

            $response = $this->requestOnce(
                $inspection,
                $headers
            );

            if (
                !in_array(
                    $response->statusCode,
                    [301, 302, 303, 307, 308],
                    true
                )
            ) {
                return $response;
            }

            $location = $response->firstHeader('location');

            if ($location === null || trim($location) === '') {
                return $response;
            }

            if ($redirects >= max(0, $this->maxRedirects)) {
                throw new RuntimeException(
                    'HTTP request exceeded the redirect limit.'
                );
            }

            $currentUrl = $this->resolveRedirect(
                $currentUrl,
                $location
            );

            $redirects++;
        }
    }

    /**
     * @param array{
     *     url: string,
     *     scheme: string,
     *     host: string,
     *     port: int,
     *     path: string,
     *     resolved_ips: list<string>,
     *     pinned_ip: string
     * } $inspection
     * @param array<string, string> $headers
     */
    private function requestOnce(
        array $inspection,
        array $headers
    ): HttpResponse {
        $handle = curl_init($inspection['url']);

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

        $pinnedIp = str_contains(
            $inspection['pinned_ip'],
            ':'
        )
            ? '[' . $inspection['pinned_ip'] . ']'
            : $inspection['pinned_ip'];

        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
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

                $position = strpos($line, ':');

                if ($position === false) {
                    return $length;
                }

                $name = mb_strtolower(
                    trim(substr($line, 0, $position))
                );

                $value = trim(
                    substr($line, $position + 1)
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

        if (
            filter_var(
                $inspection['host'],
                FILTER_VALIDATE_IP
            ) === false
        ) {
            $options[CURLOPT_RESOLVE] = [
                sprintf(
                    '%s:%d:%s',
                    $inspection['host'],
                    $inspection['port'],
                    $pinnedIp
                ),
            ];
        }

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[constant('CURLOPT_PROTOCOLS_STR')] =
                $inspection['scheme'];
        } elseif (defined('CURLOPT_PROTOCOLS')) {
            $options[constant('CURLOPT_PROTOCOLS')] =
                $inspection['scheme'] === 'https'
                    ? CURLPROTO_HTTPS
                    : CURLPROTO_HTTP;
        }

        $startedAt = hrtime(true);
        $statusCode = null;
        $success = false;
        $errorCode = null;

        try {
            if (!curl_setopt_array($handle, $options)) {
                throw new RuntimeException(
                    'Could not configure cURL request.'
                );
            }

            $executed = curl_exec($handle);
            $curlError = curl_error($handle);
            $curlErrorNumber = curl_errno($handle);
            $statusCode = (int) curl_getinfo(
                $handle,
                CURLINFO_HTTP_CODE
            );

            if ($responseTooLarge) {
                throw new RuntimeException(
                    'HTTP response exceeded the configured size limit.'
                );
            }

            if ($executed === false) {
                $errorCode = 'curl_' . $curlErrorNumber;

                throw new RuntimeException(
                    'HTTP request failed: ' . $curlError
                );
            }

            $success = $statusCode >= 200
                && $statusCode < 400;

            return new HttpResponse(
                statusCode: $statusCode,
                headers: $responseHeaders,
                body: $responseBody
            );
        } catch (Throwable $exception) {
            $errorCode ??= $exception::class;
            throw $exception;
        } finally {
            curl_close($handle);

            $durationMs = max(
                0.0,
                (hrtime(true) - $startedAt) / 1_000_000
            );

            $this->metrics?->record(
                provider: $inspection['host'],
                method: 'GET',
                host: $inspection['host'],
                path: $inspection['path'],
                statusCode: $statusCode,
                durationMs: $durationMs,
                responseBytes: strlen($responseBody),
                success: $success,
                errorCode: $errorCode
            );
        }
    }

    private function resolveRedirect(
        string $baseUrl,
        string $location
    ): string {
        $location = trim($location);

        if (filter_var($location, FILTER_VALIDATE_URL)) {
            return $location;
        }

        $base = parse_url($baseUrl);

        if (!is_array($base)) {
            throw new RuntimeException(
                'Redirect base URL is invalid.'
            );
        }

        if (str_starts_with($location, '//')) {
            return (string) $base['scheme']
                . ':'
                . $location;
        }

        $authority = (string) $base['scheme']
            . '://'
            . (string) $base['host'];

        if (isset($base['port'])) {
            $authority .= ':' . (int) $base['port'];
        }

        if (str_starts_with($location, '/')) {
            return $authority . $location;
        }

        $basePath = (string) ($base['path'] ?? '/');
        $directory = rtrim(
            str_replace('\\', '/', dirname($basePath)),
            '/'
        );

        $combined = ($directory !== '' ? $directory : '')
            . '/'
            . $location;

        $segments = [];

        foreach (explode('/', $combined) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return $authority . '/' . implode('/', $segments);
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

        if (
            str_contains($value, "\r")
            || str_contains($value, "\n")
        ) {
            throw new RuntimeException(
                'HTTP header value is invalid.'
            );
        }

        if (in_array(
            mb_strtolower($name),
            ['authorization', 'cookie', 'proxy-authorization'],
            true
        )) {
            throw new RuntimeException(
                'Sensitive outbound HTTP headers are blocked.'
            );
        }
    }
}
