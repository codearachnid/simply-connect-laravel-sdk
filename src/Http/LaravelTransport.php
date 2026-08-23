<?php

declare(strict_types=1);

namespace SimplyConnect\Laravel\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use SimplyConnect\Exception\TransportException;
use SimplyConnect\Http\Transport;

/**
 * Sends SDK requests through Laravel's HTTP client, so Http::fake(),
 * Http::assertSent() and Telescope all see Nuvei traffic.
 */
final class LaravelTransport implements Transport
{
    public function __construct(
        private readonly Factory $http,
        private readonly int $timeout = 30,
    ) {
    }

    public function post(string $url, array $payload): string
    {
        try {
            $response = $this->http->acceptJson()->timeout($this->timeout)->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new TransportException(sprintf('Could not reach %s: %s', $url, $e->getMessage()), 0, $e);
        }

        if (!$response->successful()) {
            throw new TransportException(sprintf('%s responded with HTTP %d: %s', $url, $response->status(), substr($response->body(), 0, 200)));
        }

        return $response->body();
    }
}
