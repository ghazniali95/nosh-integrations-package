<?php

namespace Nosh\OmniConnect\Credentials;

use Nosh\OmniConnect\Contracts\CredentialResolver;
use Nosh\OmniConnect\Data\Credentials;

/**
 * Single-install resolver: reads the vendor identity + login from config/.env.
 */
class EnvCredentialResolver implements CredentialResolver
{
    public function __construct(protected array $config)
    {
    }

    public function resolve(mixed $tenant = null, ?string $platform = null): ?Credentials
    {
        $platform ??= $this->config['default'] ?? 'delivery_hero';
        $vendor = $this->config['vendor'] ?? [];

        if (empty($vendor['username']) && empty($vendor['password'])) {
            // Nothing configured — mock transport still works (login is faked),
            // but signal "unconfigured" so callers can surface it in http mode.
            return new Credentials(platform: $platform, sandbox: (bool) ($this->config['sandbox'] ?? true));
        }

        return new Credentials(
            platform: $platform,
            username: $vendor['username'] ?? null,
            password: $vendor['password'] ?? null,
            sandbox: (bool) ($this->config['sandbox'] ?? true),
            chainCode: $vendor['chain_code'] ?? null,
            vendorIds: $vendor['vendor_ids'] ?? [],
            webhookSecret: $this->config['webhook']['secret'] ?? null,
            tenantId: null,
        );
    }
}
