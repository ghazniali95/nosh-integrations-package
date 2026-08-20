<?php

namespace Nosh\OmniConnect\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Nosh\OmniConnect\OmniConnectManager platform(string $platform)
 * @method static \Nosh\OmniConnect\OmniConnectManager for(mixed $tenant)
 * @method static \Nosh\OmniConnect\OmniConnectManager sandbox(bool $on = true)
 * @method static void resolveCredentialsUsing(\Closure $callback)
 * @method static void resolveInboundUsing(\Closure $callback)
 * @method static \Nosh\OmniConnect\Data\Credentials|null credentialsForRemoteId(string $remoteId, ?string $platform = null)
 * @method static \Nosh\OmniConnect\Orders\OrderClient orders()
 * @method static \Nosh\OmniConnect\Catalog\CatalogClient catalog()
 * @method static \Nosh\OmniConnect\Store\StoreClient store()
 * @method static \Nosh\OmniConnect\Reports\ReportClient reports()
 * @method static \Nosh\OmniConnect\Contracts\DeliveryPlatformDriver driver()
 * @method static \Nosh\OmniConnect\Data\Credentials credentials()
 *
 * @see \Nosh\OmniConnect\OmniConnectManager
 */
class OmniConnect extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'omniconnect';
    }
}
