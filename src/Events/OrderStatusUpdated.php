<?php

namespace Nosh\OmniConnect\Events;

use Nosh\OmniConnect\Data\Order;

/**
 * A generic inbound order status update from Delivery Hero. Fired for EVERY
 * posOrderStatus notification, carrying the platform's raw status enum so the
 * host can react to all of them:
 *   ORDER_CANCELLED, ORDER_PICKED_UP,
 *   PRODUCT_ORDER_MODIFICATION_SUCCESSFUL / _FAILED  (async result of modifyProducts),
 *   COURIER_ARRIVED_AT_VENDOR,
 *   SHOW_RIDER_WAITING_WARNING / HIDE_RIDER_WAITING_WARNING.
 *
 * OrderCancelled is still fired additionally for the cancellation case.
 */
class OrderStatusUpdated
{
    public function __construct(
        /** The raw Delivery Hero status enum (e.g. PRODUCT_ORDER_MODIFICATION_FAILED). */
        public string $rawStatus,
        public ?string $message,
        public Order $order,
        public array $payload = [],
    ) {
    }
}
