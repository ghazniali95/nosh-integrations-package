<?php

namespace Nosh\OmniConnect\Data;

/**
 * One line on a canonical Order. Modifiers/toppings are nested as child
 * OrderItems so recipe/inventory logic can treat them uniformly.
 *
 * `remoteId` is the platform's product/variation id — the stable key your
 * recipe bindings map to (never the price).
 */
class OrderItem
{
    public function __construct(
        public string $remoteId,
        public string $name,
        public int $quantity = 1,
        /** Unit price in minor units (paisa). */
        public int $unitPrice = 0,
        /** Line total in minor units (paisa). */
        public int $total = 0,
        /** @var OrderItem[] modifiers / toppings / extras */
        public array $modifiers = [],
        public ?string $variation = null,
        public ?string $comment = null,
        /**
         * The platform's own product id, kept apart from `remoteId` (which
         * prefers your `remoteCode`). A menu that was built on the platform,
         * not pushed from your POS, often has no remoteCode — this is then
         * the only stable key to link the line to your menu by.
         */
        public ?string $platformId = null,
        /** The remoteCode exactly as sent (null when the platform has none). */
        public ?string $remoteCode = null,
        /** Discount applied to this line, minor units. */
        public int $discount = 0,
    ) {
    }

    public function toArray(): array
    {
        return [
            'remote_id'  => $this->remoteId,
            'name'       => $this->name,
            'quantity'   => $this->quantity,
            'unit_price' => $this->unitPrice,
            'total'      => $this->total,
            'variation'  => $this->variation,
            'comment'    => $this->comment,
            'platform_id' => $this->platformId,
            'remote_code' => $this->remoteCode,
            'discount'    => $this->discount,
            'modifiers'  => array_map(fn (OrderItem $m) => $m->toArray(), $this->modifiers),
        ];
    }
}
