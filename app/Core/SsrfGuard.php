<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

use RuntimeException;

final class SsrfGuard
{
    /**
     * شبکه‌هایی که برای درخواست خروجی مجاز نیستند.
     * این فهرست علاوه بر FILTER_FLAGهای PHP اعمال می‌شود تا
     * محدوده‌هایی مانند CGNAT و IPv4-mapped IPv6 نیز پوشش داده شوند.
     *
     * @var list<string>
     */
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '100::/64',
        '2001:db8::/32',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /**
     * @param list<int> $allowedPorts
     */
    public function __construct(
        private readonly bool $allowHttp = false,
        private readonly array $allowedPorts = [443]
    ) {
    }

    /**
     * @return array{
     *     url: string,
     *     scheme: string,
     *     host: string,
     *     port: int,
     *     path: string,
     *     resolved_ips: list<string>,
     *     pinned_ip: string
     * }
     */
    public function inspect(string $url): array
    {
        $url = trim($url);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException(
                'HTTP request URL is invalid.'
            );
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            throw new RuntimeException(
                'HTTP request URL could not be parsed.'
            );
        }

        $scheme = mb_strtolower(
            (string) ($parts['scheme'] ?? '')
        );

        $allowedSchemes = $this->allowHttp
            ? ['http', 'https']
            : ['https'];

        if (!in_array($scheme, $allowedSchemes, true)) {
            throw new RuntimeException(
                'HTTP URL scheme is not allowed.'
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException(
                'HTTP URLs containing credentials are not allowed.'
            );
        }

        $host = trim(
            mb_strtolower(
                rtrim(
                    (string) ($parts['host'] ?? ''),
                    '.'
                )
            ),
            '[]'
        );

        if ($host === '') {
            throw new RuntimeException(
                'HTTP URL host is missing.'
            );
        }

        if (
            !preg_match('/^[a-z0-9.-]+$/', $host)
            && filter_var($host, FILTER_VALIDATE_IP) === false
        ) {
            throw new RuntimeException(
                'International or malformed hostnames are not allowed.'
            );
        }

        if (
            $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
        ) {
            throw new RuntimeException(
                'Local hostnames are not allowed.'
            );
        }

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);

        $allowedPorts = array_map(
            static fn (mixed $value): int => (int) $value,
            $this->allowedPorts
        );

        if (!in_array($port, $allowedPorts, true)) {
            throw new RuntimeException(
                'HTTP destination port is not allowed.'
            );
        }

        $resolvedIps = $this->resolveHost($host);

        if ($resolvedIps === []) {
            throw new RuntimeException(
                'HTTP destination hostname could not be resolved.'
            );
        }

        foreach ($resolvedIps as $ip) {
            $this->assertPublicIp($ip);
        }

        usort(
            $resolvedIps,
            static function (string $left, string $right): int {
                $leftIsV4 = filter_var(
                    $left,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_IPV4
                ) !== false;

                $rightIsV4 = filter_var(
                    $right,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_IPV4
                ) !== false;

                return ($rightIsV4 ? 1 : 0)
                    <=> ($leftIsV4 ? 1 : 0);
            }
        );

        $path = (string) ($parts['path'] ?? '/');

        if ($path === '') {
            $path = '/';
        }

        return [
            'url' => $url,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'path' => $path,
            'resolved_ips' => array_values(array_unique($resolvedIps)),
            'pinned_ip' => $resolvedIps[0],
        ];
    }

    public function assertPublicIp(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException(
                'Resolved destination is not a valid IP address.'
            );
        }

        if (
            filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE
                | FILTER_FLAG_NO_RES_RANGE
            ) === false
        ) {
            throw new RuntimeException(
                'Private or reserved destination IP is blocked.'
            );
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if ($this->inCidr($ip, $cidr)) {
                throw new RuntimeException(
                    'Private, local or reserved destination IP is blocked.'
                );
            }
        }

        $mappedIpv4 = $this->mappedIpv4($ip);

        if ($mappedIpv4 !== null) {
            $this->assertPublicIp($mappedIpv4);
        }
    }

    private function mappedIpv4(string $ip): ?string
    {
        $binary = @inet_pton($ip);

        if (
            $binary === false
            || strlen($binary) !== 16
            || substr($binary, 0, 10) !== str_repeat("\0", 10)
            || substr($binary, 10, 2) !== "\xff\xff"
        ) {
            return null;
        }

        $mapped = @inet_ntop(substr($binary, 12, 4));

        return is_string($mapped)
            ? $mapped
            : null;
    }

    private function inCidr(
        string $ip,
        string $cidr
    ): bool {
        [$network, $prefixLength] = explode(
            '/',
            $cidr,
            2
        );

        $ipBinary = @inet_pton($ip);
        $networkBinary = @inet_pton($network);

        if (
            $ipBinary === false
            || $networkBinary === false
            || strlen($ipBinary) !== strlen($networkBinary)
        ) {
            return false;
        }

        $prefix = (int) $prefixLength;
        $maximumBits = strlen($ipBinary) * 8;

        if ($prefix < 0 || $prefix > $maximumBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if (
            $fullBytes > 0
            && substr($ipBinary, 0, $fullBytes)
                !== substr($networkBinary, 0, $fullBytes)
        ) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (
            ord($ipBinary[$fullBytes]) & $mask
        ) === (
            ord($networkBinary[$fullBytes]) & $mask
        );
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $records = @dns_get_record(
            $host,
            DNS_A | DNS_AAAA
        );

        if (is_array($records)) {
            foreach ($records as $record) {
                if (is_string($record['ip'] ?? null)) {
                    $ips[] = $record['ip'];
                }

                if (is_string($record['ipv6'] ?? null)) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $ipv4 = @gethostbynamel($host);

            if (is_array($ipv4)) {
                $ips = [...$ips, ...$ipv4];
            }
        }

        return array_values(array_unique($ips));
    }
}
