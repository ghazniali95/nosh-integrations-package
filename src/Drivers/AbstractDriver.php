<?php

namespace Nosh\OmniConnect\Drivers;

use Nosh\OmniConnect\Contracts\DeliveryPlatformDriver;
use Nosh\OmniConnect\Data\Credentials;

/**
 * Shared host/url/auth plumbing for platform drivers.
 */
abstract class AbstractDriver implements DeliveryPlatformDriver
{
    public function __construct(
        protected Credentials $credentials,
        protected array $platformConfig,
        protected array $config = [],
    ) {
    }

    public function label(): string
    {
        return $this->platformConfig['label'] ?? $this->key();
    }

    public function host(): string
    {
        $hosts = $this->platformConfig['hosts'] ?? [];

        return $this->credentials->sandbox
            ? ($hosts['sandbox'] ?? '')
            : ($hosts['production'] ?? '');
    }

    public function url(string $path): string
    {
        return rtrim($this->host(), '/').'/'.ltrim($path, '/');
    }

    public function authHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function parseError(array $body): string
    {
        return $body['message'] ?? $body['error'] ?? $body['code'] ?? 'Unknown platform error.';
    }
}
