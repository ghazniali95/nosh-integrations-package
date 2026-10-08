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
        /**
         * The POS-side vendor id the platform addressed this order to (the
         * {remoteId} in the webhook URL). Distinct from `vendorId`, which is
         * the platform's own restaurant id. Map branches on THIS one.
         */
        public ?string $remoteId = null,
        /** Order-level discounts in minor units, summed. */
        public int $discountTotal = 0,
        /**
         * Each discount as the platform reported it:
         * [{name, amount (paisa), type, sponsor}] — `sponsor` says who funds it
         * (e.g. platform vs vendor) when the platform tells us.
         * @var array<int,array{name:?string,amount:int,type:?string,sponsor:?string}>
         */
        public array $discounts = [],
        /** Container / packaging charge, minor units. */
        public int $containerCharge = 0,
        /** Rider tip, minor units. Not restaurant revenue. */
        public int $riderTip = 0,
        /** Cash the courier must collect at the door (COD), minor units. */
        public int $collectFromCustomer = 0,
        /** What the platform says it will pay the restaurant, minor units, when sent. */
        public ?int $payRestaurant = null,
        /** Payment type as the platform named it (e.g. `paid`, `cash`). */
        public ?string $paymentType = null,
        /** Scheduled for later rather than ASAP. */
        public bool $preOrder = false,
        /** When the platform expects the food at the door / ready for collection (ISO-8601). */
        public ?string $expectedDeliveryTime = null,
        /** When the platform's courier is due to collect (ISO-8601). Set for platform-delivered orders. */
        public ?string $riderPickupTime = null,
        /** When a pickup customer is due (ISO-8601). */
        public ?string $pickupTime = null,
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
            'remote_id'              => $this->remoteId,
            'discount_total'         => $this->discountTotal,
            'discounts'              => $this->discounts,
            'container_charge'       => $this->containerCharge,
            'rider_tip'              => $this->riderTip,
            'collect_from_customer'  => $this->collectFromCustomer,
            'pay_restaurant'         => $this->payRestaurant,
            'payment_type'           => $this->paymentType,
            'pre_order'              => $this->preOrder,
            'expected_delivery_time' => $this->expectedDeliveryTime,
            'rider_pickup_time'      => $this->riderPickupTime,
            'pickup_time'            => $this->pickupTime,
        ];
    }
}
