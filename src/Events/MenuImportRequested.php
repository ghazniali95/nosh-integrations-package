<?php

namespace Nosh\OmniConnect\Events;

/**
 * The platform asked us to (re)build and submit the menu for a vendor.
 *
 * Respond asynchronously: your listener pushes the catalog via
 * CatalogClient::submitCatalog(), or — for the legacy XML path — replies with
 * CatalogClient::submitTriggeredMenuXml($vendorCode, $menuImportId, $xml), which
 * is exactly why those two identifiers are carried here.
 */
class MenuImportRequested
{
    public function __construct(
        public string $remoteId,
        public ?string $vendorCode = null,
        public ?string $menuImportId = null,
        public array $raw = [],
    ) {
    }
}
