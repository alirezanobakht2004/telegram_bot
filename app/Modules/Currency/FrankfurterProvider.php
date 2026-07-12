<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Currency;

use RuntimeException;
use SmartToolbox\Core\HttpClient;

final class FrankfurterProvider implements CurrencyProviderInterface
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl
    ) {
    }

    /**
     * @return array{
     *     base: string,
     *     quote: string,
     *     rate: float,
     *     date: string
     * }
     */
    public function quote(
        string $base,
        string $quote
    ): array {
        $base = mb_strtoupper(trim($base));
        $quote = mb_strtoupper(trim($quote));

        $this->assertCurrencyCode($base);
        $this->assertCurrencyCode($quote);

        if ($base === $quote) {
            return [
                'base' => $base,
                'quote' => $quote,
                'rate' => 1.0,
                'date' => date('Y-m-d'),
            ];
        }

        $url = rtrim($this->baseUrl, '/')
            . '/rate/'
            . rawurlencode($base)
            . '/'
            . rawurlencode($quote);

        $data = $this->http
            ->get($url)
            ->requireSuccess()
            ->jsonArray();

        $rate = $data['rate'] ?? null;

        if (
            !is_numeric($rate)
            || (float) $rate <= 0
            || !is_finite((float) $rate)
        ) {
            throw new RuntimeException(
                'Currency provider returned an invalid rate.'
            );
        }

        $responseBase = is_string($data['base'] ?? null)
            ? mb_strtoupper($data['base'])
            : $base;

        $responseQuote = is_string($data['quote'] ?? null)
            ? mb_strtoupper($data['quote'])
            : $quote;

        $date = is_string($data['date'] ?? null)
            ? $data['date']
            : date('Y-m-d');

        return [
            'base' => $responseBase,
            'quote' => $responseQuote,
            'rate' => (float) $rate,
            'date' => $date,
        ];
    }

    private function assertCurrencyCode(string $code): void
    {
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new RuntimeException(
                'Currency code must contain exactly three letters.'
            );
        }
    }
}
