<?php

namespace Nosh\OmniConnect\Data;

/**
 * A resolved connection for one vendor (restaurant/chain) to one delivery
 * platform: which platform, sandbox vs production, the login credentials,
 * the vendor/chain identifiers, and the webhook secret used to verify
 * inbound calls.
 *
 * The short-lived access token is NOT stored here — it's fetched and cached
 * by TokenManager from these credentials on demand.
 */
class Credentials
{
    public function __construct(
        public string $platform,
        public ?string $username = null,
        public ?string $password = null,
        public bool $sandbox = true,
        public ?string $chainCode = null,
        /** @var string[] platform vendor/store ids this vendor owns */
        public array $vendorIds = [],
        public ?string $webhookSecret = null,
        public ?string $tenantId = null,
    ) {
    }

    public function hasLogin(): bool
    {
        return ! empty($this->username) && ! empty($this->password);
    }

    /** Stable cache key for this vendor's access token. */
    public function tokenCacheKey(): string
    {
        return 'omniconnect:token:'.$this->platform.':'.($this->tenantId ?? md5((string) $this->username));
    }

    public static function fromArray(array $d): self
    {
        $vendorIds = $d['vendor_ids'] ?? $d['vendorIds'] ?? [];
        if (is_string($vendorIds)) {
            $vendorIds = array_values(array_filter(array_map('trim', explode(',', $vendorIds))));
        }

        return new self(
            platform: $d['platform'] ?? 'delivery_hero',
            username: $d['username'] ?? null,
            password: $d['password'] ?? null,
            sandbox: (bool) ($d['sandbox'] ?? true),
            chainCode: $d['chain_code'] ?? $d['chainCode'] ?? null,
            vendorIds: $vendorIds,
            webhookSecret: $d['webhook_secret'] ?? $d['webhookSecret'] ?? null,
            tenantId: $d['tenant_id'] ?? $d['tenantId'] ?? null,
        );
    }
}
