<?php

namespace Nosh\OmniConnect\Models;

use Illuminate\Database\Eloquent\Model;
use Nosh\OmniConnect\Data\Credentials;

/**
 * Per-tenant platform credentials (multi-tenant / database driver).
 *
 * `password` and `webhook_secret` are encrypted at rest via Laravel's
 * 'encrypted' cast. `vendor_ids` is a JSON array of platform store ids.
 */
class OmniConnectCredential extends Model
{
    protected $table = 'omniconnect_credentials';

    protected $guarded = [];

    protected $casts = [
        'sandbox'        => 'boolean',
        'vendor_ids'     => 'array',
        'password'       => 'encrypted',
        'webhook_secret' => 'encrypted',
    ];

    /**
     * Find the tenant that owns a given platform vendor id (`remoteId`) — used
     * to route inbound webhooks to the right restaurant. Matches the id inside
     * the `vendor_ids` JSON array, or a tenant whose id equals the remoteId.
     */
    public function scopeForRemoteId($query, string $remoteId, ?string $platform = null)
    {
        return $query
            ->when($platform, fn ($q) => $q->where('platform', $platform))
            ->where(function ($q) use ($remoteId) {
                $q->whereJsonContains('vendor_ids', $remoteId)
                  ->orWhere('tenant_id', $remoteId);
            });
    }

    public function toCredentials(): Credentials
    {
        return new Credentials(
            platform: $this->platform,
            username: $this->username,
            password: $this->password,
            sandbox: (bool) $this->sandbox,
            chainCode: $this->chain_code,
            vendorIds: $this->vendor_ids ?? [],
            webhookSecret: $this->webhook_secret,
            tenantId: (string) $this->tenant_id,
        );
    }
}
