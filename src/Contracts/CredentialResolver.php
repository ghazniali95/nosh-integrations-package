<?php

namespace Nosh\OmniConnect\Contracts;

use Nosh\OmniConnect\Data\Credentials;

/**
 * Resolves the connection details for a given tenant + platform.
 *
 * Implementations: Env (single install), Database (per-tenant table),
 * Callback (host-supplied closure).
 */
interface CredentialResolver
{
    public function resolve(mixed $tenant = null, ?string $platform = null): ?Credentials;
}
