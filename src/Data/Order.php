<?php

namespace Nosh\OmniConnect\Data;

/**
 * Canonical, platform-neutral order.
 *
 * Delivery Hero's raw dispatch payload is mapped into this shape by the driver
 * so the rest of your app (KDS, POS, inventory deduction) never sees
 * platform-specific field names. A future platform maps into the same shape.
 *
 * Money fields are integer minor units (paisa) to match the Nosh convention.
 */
class Order
{
    public function __construct(
        /** Platform's order token — the id used on status-update calls. */
        public string $orderToken,
        /** Short human order code shown to staff/customer (e.g. "b2c4-f8"). */
        public ?string $orderCode = null,
        public ?string $platform = 'delivery_hero',
        /** Platform vendor/store id this order belongs to. */
        public ?string $vendorId = null,
        /** 'received' | 'accepted' | 'rejected' | 'prepared' | 'picked_up' | 'cancelled' */
        public string $status = 'received',
        /** 'delivery' | 'pickup' | 'dine_in' */
        public ?string $expeditionType = null,
        public ?Customer $customer = null,
        /** @var OrderItem[] */
        public array $items = [],
        /** Order-level totals in minor units (paisa). */
        public int $subtotal = 0,
        public int $deliveryFee = 0,
        public int $total = 0,
        public ?string $currency = 'PKR',
        public ?string $comment = null,
        /** Whether the customer paid online (vs cash on delivery). */
        public bool $paidOnline = false,
        public ?string $placedAt = null,
        /** A test order — must NOT be prepared in the kitchen. */
        public bool $test = false,
        /**
         * Per-order callback URLs Delivery Hero provides for status updates:
         * orderAcceptedUrl, orderRejectedUrl, orderPickedUpUrl, orderPreparedUrl,
         * orderProductModificationUrl, orderPreparationTimeAdjustmentUrl.
         * @var array<string,string>
         */
        public array $callbackUrls = [],
        /** The de-duplication key: platform order token (or an event id). */
        public ?string $eventId = null,
        /** The untouched raw payload, for audit / debugging. */
        public array $raw = [],
    ) {
    }

    /** Sum of item line quantities. */
    public function itemCount(): int
    {
        return array_sum(array_map(fn (OrderItem $i) => $i->quantity, $this->items));
    }

    public function toArray(): array
    {
        return [
            'order_token'     => $this->orderToken,
            'order_code'      => $this->orderCode,
            'platform'        => $this->platform,
            'vendor_id'       => $this->vendorId,
            'status'          => $this->status,
            'expedition_type' => $this->expeditionType,
            'customer'        => $this->customer?->toArray(),
            'items'           => array_map(fn (OrderItem $i) => $i->toArray(), $this->items),
            'subtotal'        => $this->subtotal,
            'delivery_fee'    => $this->deliveryFee,
            'total'           => $this->total,
            'currency'        => $this->currency,
            'comment'         => $this->comment,
            'paid_online'     => $this->paidOnline,
            'placed_at'       => $this->placedAt,
            'test'            => $this->test,
            'callback_urls'   => $this->callbackUrls,
            'event_id'        => $this->eventId,
        ];
    }
}
