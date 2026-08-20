<?php

namespace Nosh\OmniConnect\Orders;

use Nosh\OmniConnect\Contracts\IncomingOrderHandler;
use Nosh\OmniConnect\Data\Order;
use Nosh\OmniConnect\Events\OrderCancelled;
use Nosh\OmniConnect\Events\OrderReceived;
use Nosh\OmniConnect\Events\OrderStatusUpdated;
use Nosh\OmniConnect\Models\OmniConnectOrder;
use Nosh\OmniConnect\OmniConnectManager;

/**
 * The inbound Plugin-API core: turns a raw Delivery Hero call into a canonical
 * Order, persists it idempotently, and drives your app through events + the
 * optional IncomingOrderHandler.
 *
 * Idempotency: the omniconnect_orders (platform, order_token) unique key means a
 * re-delivered dispatch returns the SAME remoteOrderId and fires side effects
 * only once — so retries never double-book or double-deduct.
 */
class OrderIntake
{
    public function __construct(protected OmniConnectManager $manager)
    {
    }

    /**
     * Handle POST /order/{remoteId}. Returns the mandatory acknowledge body
     * carrying our remoteOrderId, which Delivery Hero uses for later callbacks.
     *
     * @return array{remoteResponse: array{remoteOrderId: string}}
     */
    public function receiveOrder(string $remoteId, array $payload): array
    {
        // Which restaurant is this webhook for? Resolve its credentials so the
        // order is tagged with the tenant and any auto-accept uses that account.
        $credentials = $this->manager->credentialsForRemoteId($remoteId);
        $driver = $credentials ? $this->manager->driver($credentials) : $this->manager->driver();
        $platform = $credentials?->platform ?? ($this->manager->config()['default'] ?? 'delivery_hero');

        $order = $driver->parseIncomingOrder($payload);
        $order->vendorId = $order->vendorId ?: $remoteId;

        $row = OmniConnectOrder::query()->firstOrNew([
            'platform'    => $platform,
            'order_token' => $order->orderToken,
        ]);

        $firstTime = ! $row->exists;

        if ($firstTime) {
            $row->fill([
                'tenant_id'       => $credentials?->tenantId,
                'order_code'      => $order->orderCode,
                'vendor_id'       => $order->vendorId,
                'status'          => 'received',
                'expedition_type' => $order->expeditionType,
                'total'           => $order->total,
                'currency'        => $order->currency,
                'paid_online'     => $order->paidOnline,
                'payload'         => $order->raw,
                'received_at'     => now(),
            ])->save();

            // Our POS-side id echoed back on all future callbacks.
            $row->remote_order_id = $row->remote_order_id ?: 'PANDA-'.$row->id;
            $row->save();

            event(new OrderReceived($order));
            $this->runHandler($order, $credentials);
        }

        return ['remoteResponse' => ['remoteOrderId' => (string) $row->remote_order_id]];
    }

    /**
     * Handle PUT /remoteId/{remoteId}/remoteOrder/{remoteOrderId}/posOrderStatus.
     * The order is addressed by OUR remoteOrderId from the path.
     */
    public function updateStatus(string $remoteId, string $remoteOrderId, array $payload): void
    {
        $order = $this->manager->driver()->parseIncomingStatus($payload);
        $rawStatus = (string) ($payload['status'] ?? '');

        $row = OmniConnectOrder::query()->where('remote_order_id', $remoteOrderId)->first();
        if ($row) {
            $order->orderToken = $row->order_token;

            // Only cancellation and pickup are true lifecycle transitions; the
            // other notifications (modification result, courier, warnings) don't
            // clobber the stored order status.
            if ($order->status === 'cancelled') {
                $row->status = 'cancelled';
                $row->cancelled_at = now();
                $row->save();
            } elseif ($order->status === 'picked_up') {
                $row->status = 'picked_up';
                $row->save();
            }
        }

        // Always surface the update (carries the raw enum, e.g. the async
        // PRODUCT_ORDER_MODIFICATION_SUCCESSFUL/_FAILED result of modifyProducts).
        event(new OrderStatusUpdated($rawStatus, $payload['message'] ?? null, $order, $payload));

        if ($order->status === 'cancelled') {
            event(new OrderCancelled($order));
            $this->handler()?->cancelled($order);
        }
    }

    // ---- Handler wiring ---------------------------------------------------

    protected function runHandler(Order $order, ?\Nosh\OmniConnect\Data\Credentials $credentials = null): void
    {
        $handler = $this->handler();
        if (! $handler) {
            return; // no handler bound — order sits as 'received' for the app to act on
        }

        $intent = $handler->handle($order);

        if ($intent === 'accepted') {
            // Honor the contract: auto-accept, through THIS tenant's account.
            $this->manager->orders($credentials)->accept($order->orderToken);
        }
    }

    protected function handler(): ?IncomingOrderHandler
    {
        $container = $this->manager->container();

        return $container->bound(IncomingOrderHandler::class)
            ? $container->make(IncomingOrderHandler::class)
            : null;
    }
}
