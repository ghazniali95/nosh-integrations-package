<?php

namespace Nosh\OmniConnect\Events;

use Nosh\OmniConnect\Data\Order;

/** We successfully accepted an order with the platform. */
class OrderAccepted
{
    public function __construct(public Order $order)
    {
    }
}
