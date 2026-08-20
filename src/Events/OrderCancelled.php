<?php

namespace Nosh\OmniConnect\Events;

use Nosh\OmniConnect\Data\Order;

/** The platform notified us an order was cancelled. */
class OrderCancelled
{
    public function __construct(public Order $order)
    {
    }
}
