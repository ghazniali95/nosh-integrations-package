<?php

namespace Nosh\OmniConnect\Store;

use Nosh\OmniConnect\Http\ChainClient;

/**
 * Store / vendor availability (Integration Middleware API).
 *
 *   GET /v2/chains/{chainCode}/remoteVendors/{posVendorId}/availability
 *   PUT /v2/chains/{chainCode}/remoteVendors/{posVendorId}/availability
 *   PUT /v2/chains/{chainCode}/remoteVendors/{posVendorId}/posReachabilityStatus (deprecated)
 *
 * A vendor is present on several platforms; the GET returns one entry per
 * platform (each with platformKey + platformRestaurantId + `changeable`). Only
 * open/close a platform whose `changeable` is true.
 */
class StoreClient extends ChainClient
{
    /**
     * Current availability per platform for a POS vendor.
     * Returns ['pending' => true] on the spec's 204 (result not ready — retry).
     */
    public function getAvailability(string $posVendorId, ?string $chainCode = null): array
    {
        $res = $this->api->raw('get', $this->availabilityUrl($posVendorId, $chainCode));

        if (($res['status'] ?? 0) === 204) {
            return ['pending' => true];
        }

        return $res['body'] ?? [];
    }

    /** Mark a platform storefront OPEN. */
    public function open(string $posVendorId, string $platformKey, string $platformRestaurantId, ?string $chainCode = null): array
    {
        return $this->api->put($this->availabilityUrl($posVendorId, $chainCode), [
            'availabilityState'    => 'OPEN',
            'platformKey'          => $platformKey,
            'platformRestaurantId' => $platformRestaurantId,
        ]);
    }

    /**
     * Mark a platform storefront CLOSED. $availabilityState is CLOSED_UNTIL
     * (with $closingMinutes) or CLOSED / CLOSED_TODAY. $closedReason from the
     * GET's closingReasons.
     */
    public function close(
        string $posVendorId,
        string $platformKey,
        string $platformRestaurantId,
        string $closedReason,
        ?int $closingMinutes = null,
        string $availabilityState = 'CLOSED_UNTIL',
        ?string $chainCode = null
    ): array {
        return $this->api->put($this->availabilityUrl($posVendorId, $chainCode), array_filter([
            'availabilityState'    => $availabilityState,
            'closedReason'         => $closedReason,
            'closingMinutes'       => $closingMinutes,
            'platformKey'          => $platformKey,
            'platformRestaurantId' => $platformRestaurantId,
        ], fn ($v) => $v !== null));
    }

    /** Deprecated POS reachability toggle. $status = 'online' | 'offline'. */
    public function setReachability(string $posVendorId, string $status, ?string $message = null, ?string $reason = null, ?string $chainCode = null): array
    {
        $url = $this->url('/v2/chains/'.$this->enc($this->chainCode($chainCode)).'/remoteVendors/'.$this->enc($posVendorId).'/posReachabilityStatus');

        return $this->api->put($url, array_filter([
            'status'  => $status,
            'message' => $message,
            'reason'  => $reason,
        ], fn ($v) => $v !== null));
    }

    protected function availabilityUrl(string $posVendorId, ?string $chainCode): string
    {
        return $this->url('/v2/chains/'.$this->enc($this->chainCode($chainCode)).'/remoteVendors/'.$this->enc($posVendorId).'/availability');
    }
}
