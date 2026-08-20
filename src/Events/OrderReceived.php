<?php

namespace Nosh\OmniConnect\Events;

use Nosh\OmniConnect\Data\Order;

/** A new order dispatch was received from the platform and normalized. */
class OrderReceived
{
    public function __construct(public Order $order)
    {
    }
}
