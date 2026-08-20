<?php

namespace Nosh\OmniConnect\Http;

use Nosh\OmniConnect\Contracts\DeliveryPlatformDriver;
use Nosh\OmniConnect\Data\Credentials;
use Nosh\OmniConnect\Exceptions\OmniConnectException;

/**
 * Base for the chain-scoped domain clients (catalog, store, reports). Most
 * Middleware endpoints are nested under /v2/chains/{chainCode}/… — this resolves
 * the chain code (call arg → credentials) and builds absolute URLs via the
 * driver's host.
 */
abstract class ChainClient
{
    public function __construct(
        protected AuthorizedClient $api,
        protected DeliveryPlatformDriver $driver,
        protected Credentials $credentials,
    ) {
    }

    protected function chainCode(?string $override = null): string
    {
        $code = $override ?: $this->credentials->chainCode;

        if (empty($code)) {
            throw new OmniConnectException('No chainCode available. Pass one, or set vendor.chain_code / the tenant credential.');
        }

        return $code;
    }

    protected function url(string $path): string
    {
        return $this->driver->url($path);
    }

    protected function enc(string $segment): string
    {
        return rawurlencode($segment);
    }
}
