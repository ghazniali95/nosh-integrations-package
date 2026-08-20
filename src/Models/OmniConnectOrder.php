<?php

namespace Nosh\OmniConnect\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Inbound-order log. Every order Delivery Hero dispatches to us is recorded
 * here keyed by (platform, order_token) — that unique key is our idempotency
 * guard, so a re-delivered webhook never creates a duplicate or double-deducts.
 *
 * The package writes/advances the row; your app reads it and acts on the
 * canonical Order handed to your IncomingOrderHandler.
 */
class OmniConnectOrder extends Model
{
    protected $table = 'omniconnect_orders';

    protected $guarded = [];

    protected $casts = [
        'payload'      => 'array',
        'paid_online'  => 'boolean',
        'received_at'  => 'datetime',
        'accepted_at'  => 'datetime',
        'rejected_at'  => 'datetime',
        'prepared_at'  => 'datetime',
        'cancelled_at' => 'datetime',
    ];
}
