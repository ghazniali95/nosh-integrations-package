<?php

namespace Nosh\OmniConnect\Contracts;

use Nosh\OmniConnect\Data\Order;

/**
 * The seam between this package and YOUR application.
 *
 * The package receives an order from Delivery Hero (Plugin API), verifies the
 * signature, de-duplicates it, and normalizes it to a canonical Order. It then
 * hands that Order to your app through this contract so YOU decide what happens
 * next — persist it, push it to the KDS/POS, trigger inventory deduction, and
 * (for direct integrations) auto-accept or reject it.
 *
 * Bind your implementation in a service provider:
 *   $this->app->bind(IncomingOrderHandler::class, MyOrderHandler::class);
 *
 * If you bind nothing, the package just fires the OrderReceived event and the
 * order stays in `omniconnect_orders` as 'received' for you to act on later.
 */
interface IncomingOrderHandler
{
    /**
     * Called after a NEW order dispatch is received and normalized.
     *
     * Return one of:
     *   'accepted' → the package will call Delivery Hero to accept it
     *   'rejected' → return via rejectWithReason() below instead
     *   null       → do nothing automatic; you'll accept/reject later yourself
     */
    public function handle(Order $order): ?string;

    /**
     * Called when Delivery Hero notifies us an order was CANCELLED.
     * Use it to reverse inventory deduction / notify the kitchen.
     */
    public function cancelled(Order $order): void;
}
