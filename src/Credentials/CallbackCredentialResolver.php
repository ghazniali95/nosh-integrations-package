<?php

namespace Nosh\OmniConnect\Credentials;

use Closure;
use Nosh\OmniConnect\Contracts\CredentialResolver;
use Nosh\OmniConnect\Data\Credentials;

/**
 * Resolves credentials via a host-supplied closure, registered with
 * OmniConnect::resolveCredentialsUsing(fn ($tenant, $platform) => ...).
 *
 * The closure may return a Credentials, an array, an Eloquent model, or null.
 */
class CallbackCredentialResolver implements CredentialResolver
{
    public function __construct(protected Closure $callback)
    {
    }

    public function resolve(mixed $tenant = null, ?string $platform = null): ?Credentials
    {
        $result = ($this->callback)($tenant, $platform);

        if ($result === null || $result instanceof Credentials) {
            return $result;
        }

        if (is_array($result)) {
            return Credentials::fromArray($result);
        }

        if (is_object($result) && method_exists($result, 'toCredentials')) {
            return $result->toCredentials();
        }

        if (is_object($result)) {
            return Credentials::fromArray((array) $result);
        }

        return null;
    }
}
