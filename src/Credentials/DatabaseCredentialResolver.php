<?php

namespace Nosh\OmniConnect\Credentials;

use Nosh\OmniConnect\Contracts\CredentialResolver;
use Nosh\OmniConnect\Data\Credentials;
use Nosh\OmniConnect\Models\OmniConnectCredential;

/**
 * Multi-tenant resolver: looks a vendor's credentials up from the
 * omniconnect_credentials table by tenant id (+ optional platform). The password and
 * webhook secret are encrypted at rest (see the model's casts).
 */
class DatabaseCredentialResolver implements CredentialResolver
{
    public function __construct(protected array $config)
    {
    }

    public function resolve(mixed $tenant = null, ?string $platform = null): ?Credentials
    {
        $platform ??= $this->config['default'] ?? 'delivery_hero';

        $tenantId = is_object($tenant) && method_exists($tenant, 'getKey')
            ? $tenant->getKey()
            : $tenant;

        $row = OmniConnectCredential::query()
            ->where('tenant_id', $tenantId)
            ->where('platform', $platform)
            ->first();

        return $row?->toCredentials();
    }
}
