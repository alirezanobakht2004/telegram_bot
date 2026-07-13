<?php

declare(strict_types=1);

namespace SmartToolbox\Core;

final class HttpClient
{
    private readonly SafeHttpClient $client;

    public function __construct(
        string $userAgent,
        int $connectTimeout = 4,
        int $timeout = 8,
        int $maxResponseBytes = 1048576,
        ?ApiMetricsTracker $metrics = null,
        ?SsrfGuard $ssrfGuard = null,
        int $maxRedirects = 3
    ) {
        $this->client = new SafeHttpClient(
            userAgent: $userAgent,
            ssrfGuard: $ssrfGuard
                ?? new SsrfGuard(
                    allowHttp: false,
                    allowedPorts: [443]
                ),
            connectTimeout: $connectTimeout,
            timeout: $timeout,
            maxResponseBytes: $maxResponseBytes,
            maxRedirects: $maxRedirects,
            metrics: $metrics
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(
        string $url,
        array $headers = []
    ): HttpResponse {
        return $this->client->get(
            $url,
            $headers
        );
    }
}
