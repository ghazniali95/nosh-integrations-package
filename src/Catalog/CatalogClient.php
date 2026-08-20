<?php

namespace Nosh\OmniConnect\Catalog;

use Nosh\OmniConnect\Http\ChainClient;

/**
 * Catalog management (Integration Middleware API):
 *
 *   PUT  /v2/chains/{chainCode}/catalog                                    submitCatalog
 *   PUT  /v2/chains/{chainCode}/global-entity/{globalEntityId}/catalog     submitCentralizedKitchenCatalog
 *   GET  /v2/chains/{chainCode}/vendors/{posVendorId}/menu-import-logs      importLogs
 *   PUT  /v2/chains/{chainCode}/vendors/{posVendorId}/catalog/items/availability  updateItemAvailability
 *   GET  /v2/chains/{chainCode}/vendors/{posVendorId}/catalog/items/unavailable   unavailableItems
 *   GET  /v2/chains/{chainCode}/vendors/{posVendorId}/platform-vendors      platformVendors
 *   PUT  /v2/chains/{chainCode}/globalEntityId/{gid}/restaurant/{pvid}/modify-product  modifyProduct
 *   POST /v2/chains/{chainCode}/remoteVendors/{posVendorId}/menuImport      submitMenuXml (deprecated)
 *   POST /v2/menu/{vendorCode}/{menuImportId}                               submitTriggeredMenuXml (deprecated)
 *
 * The catalog body follows Delivery Hero's Catalog schema; build it with
 * CatalogBuilder or pass a ready array. The item-availability helpers below
 * cover the common enable/disable cases without hand-building the body.
 */
class CatalogClient extends ChainClient
{
    /** Submit a full catalog for one or more vendors of a chain. Returns the import response (202). */
    public function submitCatalog(array $catalog, ?string $chainCode = null): array
    {
        return $this->api->put(
            $this->url('/v2/chains/'.$this->enc($this->chainCode($chainCode)).'/catalog'),
            $catalog
        );
    }

    /** Submit a catalog for centralized-kitchen vendors (platform vendor ids) under a global entity. */
    public function submitCentralizedKitchenCatalog(array $catalog, string $globalEntityId, ?string $chainCode = null): array
    {
        return $this->api->put(
            $this->url('/v2/chains/'.$this->enc($this->chainCode($chainCode)).'/global-entity/'.$this->enc($globalEntityId).'/catalog'),
            $catalog
        );
    }

    /** Catalog import logs for a vendor. $query accepts from/to/limit/sort. */
    public function importLogs(string $posVendorId, array $query = [], ?string $chainCode = null): array
    {
        return $this->api->get(
            $this->url('/v2/chains/'.$this->enc($this->chainCode($chainCode)).'/vendors/'.$this->enc($posVendorId).'/menu-import-logs'),
            $query
        );
    }

    // ---- Item availability ------------------------------------------------

    /** Raw item-availability update (compose the body yourself). */
    public function updateItemAvailability(string $posVendorId, array $body, ?string $chainCode = null): array
    {
        return $this->api->put($this->itemAvailabilityUrl($posVendorId, $chainCode), $body);
    }

    /** Enable items/toppings. $type = 'ITEM' | 'TOPPING'. */
    public function enableItems(string $posVendorId, array $itemIds, string $globalEntityId, string $type = 'ITEM', ?string $chainCode = null): array
    {
        return $this->updateItemAvailability($posVendorId, [
            'globalEntityId' => $globalEntityId,
            'items'          => array_values($itemIds),
            'type'           => $type,
            'isAvailable'    => true,
        ], $chainCode);
    }

    /**
     * Disable items/toppings. Optionally schedule re-availability:
     *   $until = null                → indefinitely
     *   $until = 'NEXT_BUSINESS_DAY' → until next business day
     *   $until = an ISO-8601 string  → until that timestamp
     */
    public function disableItems(string $posVendorId, array $itemIds, string $globalEntityId, string $type = 'ITEM', ?string $until = null, ?string $chainCode = null): array
    {
        $body = [
            'globalEntityId' => $globalEntityId,
            'items'          => array_values($itemIds),
            'type'           => $type,
            'isAvailable'    => false,
        ];

        if ($until === 'NEXT_BUSINESS_DAY') {
            $body['willBeAvailable'] = 'NEXT_BUSINESS_DAY';
        } elseif ($until !== null) {
            $body['willBeAvailable'] = 'AT_TIMESTAMP';
            $body['atTimeStamp'] = $until;
        }

        return $this->updateItemAvailability($posVendorId, $body, $chainCode);
    }

    /** Items/toppings currently unavailable across platforms (in development). */
    public function unavailableItems(string $posVendorId, ?string $chainCode = null): array
    {
        return $this->api->get(
            $this->url('/v2/chains/'.$this->enc($this->chainCode($chainCode)).'/vendors/'.$this->enc($posVendorId).'/catalog/items/unavailable')
        );
    }

    // ---- Vendor metadata & product modification --------------------------

    /** Platform vendors for a POS vendor id. */
    public function platformVendors(string $posVendorId, ?string $chainCode = null): array
    {
        return $this->api->get(
            $this->url('/v2/chains/'.$this->enc($this->chainCode($chainCode)).'/vendors/'.$this->enc($posVendorId).'/platform-vendors')
        );
    }

    /** Modify product details for a platform restaurant (Pandora). */
    public function modifyProduct(string $globalEntityId, string $platformVendorId, array $body, ?string $chainCode = null): array
    {
        return $this->api->put(
            $this->url('/v2/chains/'.$this->enc($this->chainCode($chainCode)).'/globalEntityId/'.$this->enc($globalEntityId).'/restaurant/'.$this->enc($platformVendorId).'/modify-product'),
            $body
        );
    }

    // ---- Deprecated XML menu import --------------------------------------

    /** Deprecated: submit a menu as XML for a vendor. Prefer submitCatalog(). */
    public function submitMenuXml(string $posVendorId, string $xml, ?string $chainCode = null): array
    {
        return $this->api->sendRaw(
            'POST',
            $this->url('/v2/chains/'.$this->enc($this->chainCode($chainCode)).'/remoteVendors/'.$this->enc($posVendorId).'/menuImport'),
            $xml,
            'application/xml'
        );
    }

    /** Deprecated: submit menu XML in response to a menu-import trigger. */
    public function submitTriggeredMenuXml(string $vendorCode, string $menuImportId, string $xml): array
    {
        return $this->api->sendRaw(
            'POST',
            $this->url('/v2/menu/'.$this->enc($vendorCode).'/'.$this->enc($menuImportId)),
            $xml,
            'application/xml'
        );
    }

    protected function itemAvailabilityUrl(string $posVendorId, ?string $chainCode): string
    {
        return $this->url('/v2/chains/'.$this->enc($this->chainCode($chainCode)).'/vendors/'.$this->enc($posVendorId).'/catalog/items/availability');
    }
}
