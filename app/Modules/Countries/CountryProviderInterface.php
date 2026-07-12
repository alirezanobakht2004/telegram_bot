<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Countries;

interface CountryProviderInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function find(string $query): ?array;

    /**
     * @return array<string, mixed>
     */
    public function random(): array;
}
