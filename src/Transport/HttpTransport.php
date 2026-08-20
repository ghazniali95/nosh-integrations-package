<?php

namespace Nosh\OmniConnect\Transport;

use Illuminate\Support\Facades\Http;
use Nosh\OmniConnect\Contracts\Transport;

/**
 * Real HTTPS transport using Laravel's HTTP client.
 */
class HttpTransport implements Transport
{
    public function __construct(
        protected int $timeout = 30,
    ) {
    }

    public function post(string $url, array $payload, array $headers = []): array
    {
        return $this->result(
            Http::withHeaders($headers)->timeout($this->timeout)->acceptJson()->asJson()->post($url, $payload)
        );
    }

    public function put(string $url, array $payload, array $headers = []): array
    {
        return $this->result(
            Http::withHeaders($headers)->timeout($this->timeout)->acceptJson()->asJson()->put($url, $payload)
        );
    }

    public function get(string $url, array $query = [], array $headers = []): array
    {
        return $this->result(
            Http::withHeaders($headers)->timeout($this->timeout)->acceptJson()->get($url, $query)
        );
    }

    public function postForm(string $url, array $params, array $headers = []): array
    {
        return $this->result(
            Http::withHeaders($headers)->timeout($this->timeout)->acceptJson()->asForm()->post($url, $params)
        );
    }

    public function sendRaw(string $method, string $url, string $rawBody, array $headers = []): array
    {
        $contentType = $headers['Content-Type'] ?? 'text/plain';

        return $this->result(
            Http::withHeaders($headers)->timeout($this->timeout)->withBody($rawBody, $contentType)->send($method, $url)
        );
    }

    protected function result($response): array
    {
        return [
            'status' => $response->status(),
            'body'   => $this->decode($response->body()),
        ];
    }

    protected function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : ['raw' => $body];
    }
}
