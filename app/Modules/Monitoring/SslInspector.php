<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Monitoring;

use RuntimeException;
use SmartToolbox\Core\SsrfGuard;

final class SslInspector
{
    public function __construct(
        private readonly SsrfGuard $guard,
        private readonly int $timeout = 8
    ) {
    }

    /**
     * @return array{
     *     host:string,
     *     issuer:string,
     *     subject:string,
     *     valid_from:int,
     *     valid_to:int,
     *     days_remaining:int,
     *     serial:string,
     *     san:list<string>
     * }
     */
    public function inspect(string $host): array
    {
        $host = $this->host($host);
        $inspection = $this->guard->inspect(
            'https://' . $host . '/'
        );
        $ip = $inspection['pinned_ip'];
        $target = 'ssl://'
            . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)
            . ':443';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'capture_peer_cert' => true,
                'capture_peer_cert_chain' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
                'disable_compression' => true,
            ],
        ]);
        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            $target,
            $errorNumber,
            $errorMessage,
            max(1, $this->timeout),
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!is_resource($socket)) {
            throw new RuntimeException(
                "اتصال TLS برقرار نشد ({$errorNumber}): {$errorMessage}"
            );
        }

        try {
            $parameters = stream_context_get_params($socket);
            $certificate = $parameters['options']['ssl']['peer_certificate']
                ?? null;

            if ($certificate === null) {
                throw new RuntimeException(
                    'گواهی SSL دریافت نشد.'
                );
            }

            $parsed = openssl_x509_parse($certificate, false);

            if (!is_array($parsed)) {
                throw new RuntimeException(
                    'گواهی SSL قابل پردازش نیست.'
                );
            }

            $validFrom = (int) ($parsed['validFrom_time_t'] ?? 0);
            $validTo = (int) ($parsed['validTo_time_t'] ?? 0);
            $issuer = $this->distinguishedName($parsed['issuer'] ?? []);
            $subject = $this->distinguishedName($parsed['subject'] ?? []);
            $sanValue = (string) (
                $parsed['extensions']['subjectAltName'] ?? ''
            );
            $san = [];

            foreach (explode(',', $sanValue) as $entry) {
                $entry = trim($entry);

                if ($entry !== '') {
                    $san[] = preg_replace('/^(DNS|IP Address):/i', '', $entry)
                        ?? $entry;
                }
            }

            return [
                'host' => $host,
                'issuer' => $issuer,
                'subject' => $subject,
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
                'days_remaining' => (int) floor(($validTo - time()) / 86400),
                'serial' => (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''),
                'san' => array_slice($san, 0, 20),
            ];
        } finally {
            fclose($socket);
        }
    }

    private function host(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, '://')) {
            $parsed = parse_url($value);
            $value = is_array($parsed)
                ? (string) ($parsed['host'] ?? '')
                : '';
        }

        $value = mb_strtolower(rtrim(trim($value, '[]'), '.'));

        if (
            $value === ''
            || (
                preg_match('/^[a-z0-9.-]+$/', $value) !== 1
                && filter_var($value, FILTER_VALIDATE_IP) === false
            )
        ) {
            throw new RuntimeException(
                'دامنه معتبر نیست.'
            );
        }

        return $value;
    }

    private function distinguishedName(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $parts = [];

        foreach (['CN', 'O', 'OU', 'C'] as $key) {
            if (is_string($value[$key] ?? null) && $value[$key] !== '') {
                $parts[] = $key . '=' . $value[$key];
            }
        }

        return implode(', ', $parts);
    }
}
