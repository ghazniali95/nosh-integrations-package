<?php

namespace Nosh\OmniConnect\Events;

/** No credentials resolved for a tenant/platform when one was needed. */
class CredentialsMissing
{
    public function __construct(public mixed $tenant, public ?string $platform)
    {
    }
}
