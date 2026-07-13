<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Monitoring;

use RuntimeException;
use SmartToolbox\Core\SsrfGuard;
use Throwable;

final class MonitorProbe
{
    public function __construct(
        private readonly string $userAgent,
        private readonly SsrfGuard $guard,
        private readonly int $connectTimeout = 4,
        private readonly int $timeout = 8,
        private readonly int $maxResponseBytes = 131072,
        private readonly int $maxRedirects = 3
    ) {
    }

    /**
     * @return array{
     *     requested_url:string,
     *     final_url:string,
     *     status_code:int,
     *     headers:array<string,list<string>>,
     *     body_preview:string,
     *     body_bytes:int,
     *     content_type:?string,
     *     primary_ip:string,
     *     redirects:list<string>,
     *     response_ms:float,
     *     dns_ms:float,
     *     connect_ms:float,
     *     tls_ms:float,
     *     ttfb_ms:float
     * }
     */
    public function probe(string $url): array
    {
        $requestedUrl = $this->normalizeUrl($url);
        $currentUrl = $requestedUrl;
        $redirects = [];
        $totalMs = 0.0;
        $last = null;

        for ($hop = 0; $hop <= max(0, $this->maxRedirects); $hop++) {
            $inspection = $this->guard->inspect($currentUrl);
            $last = $this->requestOnce($inspection);
            $totalMs += $last['response_ms'];
            $status = $last['status_code'];
            $location = $last['headers']['location'][0] ?? null;

            if (
                !in_array($status, [301, 302, 303, 307, 308], true)
                || !is_string($location)
                || trim($location) === ''
            ) {
                break;
            }

            if ($hop >= $this->maxRedirects) {
                throw new RuntimeException(
                    'تعداد Redirectها از حد مجاز بیشتر است.'
                );
            }

            $nextUrl = $this->resolveRedirect(
                $currentUrl,
                $location
            );

            // DNS و محدوده IP مقصد هر Redirect دوباره بررسی می‌شود.
            $this->guard->inspect($nextUrl);
            $redirects[] = $nextUrl;
            $currentUrl = $nextUrl;
        }

        if (!is_array($last)) {
            throw new RuntimeException(
                'پاسخ مانیتور دریافت نشد.'
            );
        }

        return [
            'requested_url' => $requestedUrl,
            'final_url' => $currentUrl,
            'status_code' => $last['status_code'],
            'headers' => $last['headers'],
            'body_preview' => $last['body_preview'],
            'body_bytes' => $last['body_bytes'],
            'content_type' => $last['content_type'],
            'primary_ip' => $last['primary_ip'],
            'redirects' => $redirects,
            'response_ms' => round($totalMs, 2),
            'dns_ms' => $last['dns_ms'],
            'connect_ms' => $last['connect_ms'],
            'tls_ms' => $last['tls_ms'],
            'ttfb_ms' => $last['ttfb_ms'],
        ];
    }

    public function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new RuntimeException(
                'آدرس سایت خالی است.'
            );
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            throw new RuntimeException(
                'آدرس سایت معتبر نیست.'
            );
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException(
                'فقط آدرس‌های http و https مجاز هستند.'
            );
        }

        if (isset($parts['fragment'])) {
            $url = preg_replace('/#.*$/', '', $url) ?? $url;
        }

        $this->guard->inspect($url);

        return $url;
    }

    /**
     * @param array{
     *     url:string,
     *     scheme:string,
     *     host:string,
     *     port:int,
     *     path:string,
     *     resolved_ips:list<string>,
     *     pinned_ip:string
     * } $inspection
     * @return array<string,mixed>
     */
    private function requestOnce(array $inspection): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException(
                'افزونه cURL روی سرور فعال نیست.'
            );
        }

        $handle = curl_init($inspection['url']);

        if ($handle === false) {
            throw new RuntimeException(
                'ساخت درخواست HTTP ممکن نشد.'
            );
        }

        $headers = [];
        $body = '';
        $tooLarge = false;
        $pinnedIp = str_contains($inspection['pinned_ip'], ':')
            ? '[' . $inspection['pinned_ip'] . ']'
            : $inspection['pinned_ip'];

        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => max(1, $this->connectTimeout),
            CURLOPT_TIMEOUT => max($this->connectTimeout, $this->timeout),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => [
                'Accept: */*',
                'Cache-Control: no-cache',
            ],
            CURLOPT_COOKIE => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function (
                mixed $curl,
                string $line
            ) use (&$headers): int {
                $length = strlen($line);
                $line = trim($line);

                if ($line === '') {
                    return $length;
                }

                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                    return $length;
                }

                $position = strpos($line, ':');

                if ($position === false) {
                    return $length;
                }

                $name = mb_strtolower(trim(substr($line, 0, $position)));
                $value = trim(substr($line, $position + 1));
                $headers[$name] ??= [];
                $headers[$name][] = $value;

                return $length;
            },
            CURLOPT_WRITEFUNCTION => function (
                mixed $curl,
                string $chunk
            ) use (&$body, &$tooLarge): int {
                $length = strlen($chunk);

                if (strlen($body) + $length > max(1024, $this->maxResponseBytes)) {
                    $tooLarge = true;
                    return 0;
                }

                $body .= $chunk;
                return $length;
            },
        ];

        if (defined('CURLOPT_PROXY')) {
            $options[CURLOPT_PROXY] = '';
        }

        if (
            filter_var($inspection['host'], FILTER_VALIDATE_IP) === false
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

        try {
            if (!curl_setopt_array($handle, $options)) {
                throw new RuntimeException(
                    'تنظیم درخواست HTTP ممکن نشد.'
                );
            }

            $executed = curl_exec($handle);
            $error = curl_error($handle);
            $errorNumber = curl_errno($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $primaryIp = (string) curl_getinfo($handle, CURLINFO_PRIMARY_IP);

            if ($tooLarge) {
                throw new RuntimeException(
                    'حجم پاسخ از محدودیت امن بیشتر شد.'
                );
            }

            if ($executed === false) {
                throw new RuntimeException(
                    "خطای HTTP ({$errorNumber}): {$error}"
                );
            }

            if ($primaryIp !== '') {
                $this->guard->assertPublicIp($primaryIp);
            }

            $nameLookup = (float) curl_getinfo($handle, CURLINFO_NAMELOOKUP_TIME);
            $connect = (float) curl_getinfo($handle, CURLINFO_CONNECT_TIME);
            $appConnect = defined('CURLINFO_APPCONNECT_TIME')
                ? (float) curl_getinfo($handle, CURLINFO_APPCONNECT_TIME)
                : 0.0;
            $startTransfer = (float) curl_getinfo($handle, CURLINFO_STARTTRANSFER_TIME);
            $total = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME);
            $contentType = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);

            return [
                'status_code' => $status,
                'headers' => $headers,
                'body_preview' => mb_substr(
                    preg_replace('/\s+/u', ' ', trim($body)) ?? trim($body),
                    0,
                    500
                ),
                'body_bytes' => strlen($body),
                'content_type' => is_string($contentType) ? $contentType : null,
                'primary_ip' => $primaryIp !== ''
                    ? $primaryIp
                    : $inspection['pinned_ip'],
                'response_ms' => round($total * 1000, 2),
                'dns_ms' => round($nameLookup * 1000, 2),
                'connect_ms' => round(max(0, $connect - $nameLookup) * 1000, 2),
                'tls_ms' => round(max(0, $appConnect - $connect) * 1000, 2),
                'ttfb_ms' => round($startTransfer * 1000, 2),
            ];
        } finally {
            curl_close($handle);
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
                'آدرس Redirect معتبر نیست.'
            );
        }

        if (str_starts_with($location, '//')) {
            return (string) $base['scheme'] . ':' . $location;
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
        $segments = [];

        foreach (explode('/', $directory . '/' . $location) as $segment) {
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
}
