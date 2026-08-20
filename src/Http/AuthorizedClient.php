<?php

namespace Nosh\OmniConnect\Http;

use Nosh\OmniConnect\Contracts\DeliveryPlatformDriver;
use Nosh\OmniConnect\Contracts\Transport;
use Nosh\OmniConnect\Data\Credentials;
use Nosh\OmniConnect\Exceptions\OmniConnectException;
use Nosh\OmniConnect\Transport\TokenManager;

/**
 * The single authenticated-HTTP choke point shared by every domain client
 * (orders, catalog, store, reports). Adds the bearer token, retries once after
 * a forced re-login on 401, and turns any non-2xx into a OmniConnectException with
 * the platform's error message.
 */
class AuthorizedClient
{
    public function __construct(
        protected DeliveryPlatformDriver $driver,
        protected Transport $transport,
        protected TokenManager $tokens,
        protected Credentials $credentials,
    ) {
    }

    public function get(string $url, array $query = []): array
    {
        return $this->guard($this->call('get', $url, $query));
    }

    public function post(string $url, array $body = []): array
    {
        return $this->guard($this->call('post', $url, $body));
    }

    public function put(string $url, array $body = []): array
    {
        return $this->guard($this->call('put', $url, $body));
    }

    /** Raw-body request (e.g. XML menu import). $contentType sets Content-Type. */
    public function sendRaw(string $method, string $url, string $rawBody, string $contentType): array
    {
        return $this->guard($this->call('sendRaw', $url, $rawBody, $contentType, $method));
    }

    /** Full {status, body} without throwing — for callers that must see 204 etc. */
    public function raw(string $method, string $url, array $bodyOrQuery = []): array
    {
        return $this->call($method, $url, $bodyOrQuery);
    }

    // ---- Internals --------------------------------------------------------

    protected function call(string $method, string $url, mixed $payload = [], ?string $contentType = null, ?string $rawMethod = null): array
    {
        $response = $this->send($method, $url, $payload, $contentType, $rawMethod);

        if (($response['status'] ?? 0) === 401) {
            $this->tokens->forget($this->credentials);
            $response = $this->send($method, $url, $payload, $contentType, $rawMethod);
        }

        return $response;
    }

    protected function send(string $method, string $url, mixed $payload, ?string $contentType, ?string $rawMethod): array
    {
        $headers = $this->driver->authHeaders($this->tokens->token($this->credentials, $this->driver));

        if ($method === 'sendRaw') {
            $headers['Content-Type'] = $contentType;

            return $this->transport->sendRaw($rawMethod ?? 'POST', $url, (string) $payload, $headers);
        }
        if ($method === 'get') {
            return $this->transport->get($url, (array) $payload, $headers);
        }

        return $this->transport->{$method}($url, (array) $payload, $headers);
    }

    protected function guard(array $response): array
    {
        $status = $response['status'] ?? 0;

        if ($status < 200 || $status >= 300) {
            throw new OmniConnectException(
                'Request failed ('.$status.'): '.$this->driver->parseError($response['body'] ?? [])
            );
        }

        return $response['body'] ?? [];
    }
}
