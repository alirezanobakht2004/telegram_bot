<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Currency;

interface CurrencyProviderInterface
{
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
    ): array;
}
