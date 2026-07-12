<?php

declare(strict_types=1);

namespace SmartToolbox\Modules\Countries;

use RuntimeException;
use SmartToolbox\Core\HttpClient;

final class CountriesDevProvider implements CountryProviderInterface
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $query): ?array
    {
        $query = trim($query);

        if ($query === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z]{2,3}$/', $query) === 1) {
            return $this->findByCode(
                mb_strtoupper($query)
            );
        }

        return $this->findByName($query);
    }

    /**
     * @return array<string, mixed>
     */
    public function random(): array
    {
        $response = $this->http->get(
            $this->endpoint('/random')
        );

        $data = $response
            ->requireSuccess()
            ->jsonArray();

        if ($this->isCountryRecord($data)) {
            return $data;
        }

        if (
            array_is_list($data)
            && is_array($data[0] ?? null)
            && $this->isCountryRecord($data[0])
        ) {
            return $data[0];
        }

        throw new RuntimeException(
            'Country provider returned an invalid random country.'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByCode(string $code): ?array
    {
        $response = $this->http->get(
            $this->endpoint(
                '/alpha/' . rawurlencode($code)
            )
        );

        if ($response->statusCode === 404) {
            return null;
        }

        $data = $response
            ->requireSuccess()
            ->jsonArray();

        if ($this->isCountryRecord($data)) {
            return $data;
        }

        if (
            array_is_list($data)
            && is_array($data[0] ?? null)
            && $this->isCountryRecord($data[0])
        ) {
            return $data[0];
        }

        throw new RuntimeException(
            'Country provider returned an invalid country record.'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByName(string $name): ?array
    {
        $response = $this->http->get(
            $this->endpoint(
                '/name/' . rawurlencode($name)
            )
        );

        if ($response->statusCode === 404) {
            return null;
        }

        $data = $response
            ->requireSuccess()
            ->jsonArray();

        if ($this->isCountryRecord($data)) {
            return $data;
        }

        if (!array_is_list($data)) {
            throw new RuntimeException(
                'Country provider returned an invalid search response.'
            );
        }

        $countries = array_values(
            array_filter(
                $data,
                fn (mixed $item): bool =>
                    is_array($item)
                    && $this->isCountryRecord($item)
            )
        );

        if ($countries === []) {
            return null;
        }

        $normalizedQuery = $this->normalize($name);

        foreach ($countries as $country) {
            $candidates = [
                $country['name'] ?? null,
                $country['nativeName'] ?? null,
                $country['alpha2Code'] ?? null,
                $country['alpha3Code'] ?? null,
            ];

            $altSpellings = $country['altSpellings'] ?? [];

            if (is_array($altSpellings)) {
                foreach ($altSpellings as $spelling) {
                    $candidates[] = $spelling;
                }
            }

            foreach ($candidates as $candidate) {
                if (
                    is_string($candidate)
                    && $this->normalize($candidate)
                        === $normalizedQuery
                ) {
                    return $country;
                }
            }
        }

        return $countries[0];
    }

    private function endpoint(string $path): string
    {
        $baseUrl = rtrim(
            trim($this->baseUrl),
            '/'
        );

        if ($baseUrl === '') {
            throw new RuntimeException(
                'Country provider base URL is not configured.'
            );
        }

        return $baseUrl . $path;
    }

    /**
     * @param array<mixed> $record
     */
    private function isCountryRecord(array $record): bool
    {
        return is_string($record['name'] ?? null)
            && trim($record['name']) !== '';
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(
            trim($value)
        );
    }
}
