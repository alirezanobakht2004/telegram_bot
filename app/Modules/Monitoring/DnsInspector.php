<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Monitoring;

use RuntimeException;

final class DnsInspector
{
    /**
     * @return array{domain:string,records:list<array<string,mixed>>}
     */
    public function inspect(string $domain): array
    {
        $domain = $this->domain($domain);
        $records = @dns_get_record(
            $domain,
            DNS_A
            | DNS_AAAA
            | DNS_CNAME
            | DNS_MX
            | DNS_NS
            | DNS_TXT
        );

        if (!is_array($records) || $records === []) {
            throw new RuntimeException(
                'رکورد DNS پیدا نشد.'
            );
        }

        $safe = [];

        foreach (array_slice($records, 0, 50) as $record) {
            if (!is_array($record)) {
                continue;
            }

            $safe[] = [
                'type' => (string) ($record['type'] ?? ''),
                'host' => (string) ($record['host'] ?? $domain),
                'ttl' => (int) ($record['ttl'] ?? 0),
                'value' => $this->value($record),
            ];
        }

        return [
            'domain' => $domain,
            'records' => $safe,
        ];
    }

    private function domain(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, '://')) {
            $parts = parse_url($value);
            $value = is_array($parts)
                ? (string) ($parts['host'] ?? '')
                : '';
        }

        $value = mb_strtolower(rtrim(trim($value, '[]'), '.'));

        if (
            $value === ''
            || preg_match('/^[a-z0-9.-]{1,253}$/', $value) !== 1
            || $value === 'localhost'
            || str_ends_with($value, '.localhost')
            || str_ends_with($value, '.local')
            || str_ends_with($value, '.internal')
        ) {
            throw new RuntimeException(
                'دامنه عمومی معتبر نیست.'
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $record
     */
    private function value(array $record): string
    {
        $type = (string) ($record['type'] ?? '');

        return match ($type) {
            'A' => (string) ($record['ip'] ?? ''),
            'AAAA' => (string) ($record['ipv6'] ?? ''),
            'CNAME' => (string) ($record['target'] ?? ''),
            'MX' => (string) ($record['pri'] ?? '')
                . ' ' . (string) ($record['target'] ?? ''),
            'NS' => (string) ($record['target'] ?? ''),
            'TXT' => mb_substr(
                (string) ($record['txt'] ?? ''),
                0,
                500
            ),
            default => '',
        };
    }
}
