<?php

namespace Nosh\OmniConnect\Orders;

use Nosh\OmniConnect\Contracts\DeliveryPlatformDriver;
use Nosh\OmniConnect\Data\Credentials;
use Nosh\OmniConnect\Data\Order;
use Nosh\OmniConnect\Events\OrderAccepted;
use Nosh\OmniConnect\Events\OrderRejected;
use Nosh\OmniConnect\Exceptions\OmniConnectException;
use Nosh\OmniConnect\Http\AuthorizedClient;
use Nosh\OmniConnect\Models\OmniConnectOrder;
use Nosh\OmniConnect\Support\RejectReason;

/**
 * Outbound order operations (Integration Middleware API):
 * accept, reject, mark picked-up, mark prepared, adjust preparation time, and
 * modify products. Advances the local omniconnect_orders row and fires events.
 */
class OrderClient
{
    public function __construct(
        protected AuthorizedClient $api,
        protected DeliveryPlatformDriver $driver,
        protected Credentials $credentials,
    ) {
    }

    public function accept(string $orderToken, array $opts = []): array
    {
        $url = $this->targetUrl($orderToken, 'orderAcceptedUrl', $opts, fn () => $this->driver->orderStatusUrl($orderToken));
        $body = $this->api->post($url, $this->driver->acceptBody($opts));

        $this->advance($orderToken, 'accepted', ['accepted_at' => now()]);
        event(new OrderAccepted($this->stub($orderToken, 'accepted')));

        return $body;
    }

    public function reject(string $orderToken, string $reason, array $opts = []): array
    {
        if (! RejectReason::isValid($reason)) {
            throw new OmniConnectException(
                "Invalid rejection reason [{$reason}]. Use a Nosh\\OmniConnect\\Support\\RejectReason constant."
            );
        }

        $url = $this->targetUrl($orderToken, 'orderRejectedUrl', $opts, fn () => $this->driver->orderStatusUrl($orderToken));
        $body = $this->api->post($url, $this->driver->rejectBody($reason, $opts));

        $this->advance($orderToken, 'rejected', ['rejected_at' => now(), 'reject_reason' => $reason]);
        event(new OrderRejected($this->stub($orderToken, 'rejected'), $reason));

        return $body;
    }

    public function markPickedUp(string $orderToken, array $opts = []): array
    {
        $url = $this->targetUrl($orderToken, 'orderPickedUpUrl', $opts, fn () => $this->driver->orderStatusUrl($orderToken));
        $body = $this->api->post($url, $this->driver->pickedUpBody());
        $this->advance($orderToken, 'picked_up', []);

        return $body;
    }

    public function markPrepared(string $orderToken, array $opts = []): array
    {
        $url = $this->targetUrl($orderToken, 'orderPreparedUrl', $opts, fn () => $this->driver->preparedUrl($orderToken));
        $body = $this->api->post($url, $this->driver->preparedBody($opts));
        $this->advance($orderToken, 'prepared', ['prepared_at' => now()]);

        return $body;
    }

    /**
     * Adjust the preparation/pickup time (logistics-delivery orders).
     * $expectedPickupAt must be RFC-3339. Returns [] on the spec's 204.
     */
    public function adjustPreparationTime(string $orderToken, string $expectedPickupAt, array $opts = []): array
    {
        $url = $this->targetUrl($orderToken, 'orderPreparationTimeAdjustmentUrl', $opts, fn () => $this->driver->adjustPreparationTimeUrl($orderToken));

        return $this->api->post($url, ['expectedPickupAt' => $expectedPickupAt]);
    }

    /**
     * Add/remove products on a live order. $modification follows the spec's
     * Modification schema ({ products: [...] }). The result is delivered
     * asynchronously via the inbound Update-Order-Status callback
     * (PRODUCT_ORDER_MODIFICATION_SUCCESSFUL / _FAILED).
     */
    public function modifyProducts(string $orderToken, array $modification, array $opts = []): array
    {
        $url = $this->targetUrl($orderToken, 'orderProductModificationUrl', $opts, fn () => $this->driver->modifyProductsUrl($orderToken));

        return $this->api->post($url, $modification);
    }

    /**
     * Resolve the URL for a status action, preferring (1) an explicit
     * $opts['callback_url'] override, (2) the per-order callbackUrls Delivery
     * Hero sent with the dispatch (stored on the order), then (3) the
     * constructed default path.
     */
    protected function targetUrl(string $orderToken, string $callbackKey, array $opts, callable $default): string
    {
        return $opts['callback_url']
            ?? $this->storedCallbackUrl($orderToken, $callbackKey)
            ?? $default();
    }

    protected function storedCallbackUrl(string $orderToken, string $key): ?string
    {
        if (! $this->tableExists()) {
            return null;
        }

        $row = OmniConnectOrder::query()
            ->where('platform', $this->credentials->platform)
            ->where('order_token', $orderToken)
            ->first();

        $url = $row?->payload['callbackUrls'][$key] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    // ---- Local state ------------------------------------------------------

    protected function advance(string $orderToken, string $status, array $extra = []): void
    {
        if (! $this->tableExists()) {
            return;
        }

        OmniConnectOrder::query()
            ->where('platform', $this->credentials->platform)
            ->where('order_token', $orderToken)
            ->update(array_merge(['status' => $status], $extra));
    }

    protected function stub(string $orderToken, string $status): Order
    {
        return new Order(orderToken: $orderToken, platform: $this->credentials->platform, status: $status);
    }

    protected function tableExists(): bool
    {
        try {
            return OmniConnectOrder::query()->getConnection()->getSchemaBuilder()->hasTable('omniconnect_orders');
        } catch (\Throwable) {
            return false;
        }
    }
}
