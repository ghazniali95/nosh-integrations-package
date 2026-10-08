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
     * The handoff to the app is retried until it succeeds. The row is written
     * first (so the ack id is stable), and `handled_at` is stamped only after
     * the IncomingOrderHandler returns. If the handler throws, the platform gets
     * a 5xx and re-sends; the re-delivery finds `handled_at` still null and runs
     * the handler again. It used to see the row, skip the handler and ack — an
     * order the app never received, acknowledged as taken.
     *
     * Because of that retry, a handler MUST be idempotent on the order token.
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
        $order->remoteId = $remoteId;
        $order->platform = $platform;

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
                'remote_id'       => $remoteId,
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

            // A notification, fired once. The handler below is the delivery
            // that must succeed, and is the one that is retried.
            event(new OrderReceived($order));
        }

        if ($row->handled_at === null) {
            $this->runHandler($order, $credentials);

            $row->handled_at = now();
            $row->save();
        }

        return ['remoteResponse' => ['remoteOrderId' => (string) $row->remote_order_id]];
    }

    /**
     * Handle PUT /remoteId/{remoteId}/remoteOrder/{remoteOrderId}/posOrderStatus.
     * The order is addressed by OUR remoteOrderId from the path.
     *
     * The row is looked up under the {remoteId} it was dispatched to, not by
     * remoteOrderId alone: in a multi-tenant install a remoteOrderId is only
     * unique per vendor in principle, and one vendor must never be able to
     * cancel another's order by guessing its id. Rows written before
     * `remote_id` existed (null) still match.
     */
    public function updateStatus(string $remoteId, string $remoteOrderId, array $payload): void
    {
        $credentials = $this->manager->credentialsForRemoteId($remoteId);
        $driver = $credentials ? $this->manager->driver($credentials) : $this->manager->driver();

        $order = $driver->parseIncomingStatus($payload);
        $order->remoteId = $remoteId;
        $rawStatus = (string) ($payload['status'] ?? '');

        $row = OmniConnectOrder::query()
            ->where('remote_order_id', $remoteOrderId)
            ->where(fn ($q) => $q->where('remote_id', $remoteId)->orWhereNull('remote_id'))
            ->when($credentials?->tenantId !== null, fn ($q) => $q->where('tenant_id', (string) $credentials->tenantId))
            ->first();

        if ($row) {
            $order->orderToken = $row->order_token;
            $order->platform = $row->platform;

            // Only cancellation and pickup are true lifecycle transitions; the
            // other notifications (modification result, courier, warnings) don't
            // clobber the stored order status.
            if ($order->status === 'cancelled') {
                $row->status = 'cancelled';
                $row->cancelled_at ??= now();
                $row->save();
            } elseif ($order->status === 'picked_up') {
                $row->status = 'picked_up';
                $row->save();
            }
        }

        // Always surface the update (carries the raw enum, e.g. the async
        // PRODUCT_ORDER_MODIFICATION_SUCCESSFUL/_FAILED result of modifyProducts).
        event(new OrderStatusUpdated($rawStatus, $payload['message'] ?? null, $order, $payload));

        // The handler only hears about orders we actually hold — a cancel for
        // an unknown id has no order to cancel, and handing it on with an empty
        // token would make every handler guard against that itself.
        if ($row && $order->status === 'cancelled') {
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
            // Honor the contract: auto-accept, through THIS tenant's account —
            // queued, so the platform's ack never waits on (or fails with) it.
            AcceptOrderJob::dispatch($order->orderToken, $credentials?->tenantId, $credentials?->platform);
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
