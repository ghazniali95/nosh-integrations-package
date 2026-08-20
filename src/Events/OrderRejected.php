<?php

namespace Nosh\OmniConnect\Events;

use Nosh\OmniConnect\Data\Order;

/** We rejected an order with the platform (carries the reason). */
class OrderRejected
{
    public function __construct(public Order $order, public string $reason)
    {
    }
}
