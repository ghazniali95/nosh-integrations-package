<?php

namespace Nosh\OmniConnect\Tests;

use Illuminate\Support\Facades\Event;
use Nosh\OmniConnect\Contracts\Transport;
use Nosh\OmniConnect\Events\MenuImportRequested;
use Nosh\OmniConnect\Events\OrderAccepted;
use Nosh\OmniConnect\Events\OrderCancelled;
use Nosh\OmniConnect\Events\OrderReceived;
use Nosh\OmniConnect\Events\OrderStatusUpdated;
use Nosh\OmniConnect\Facades\OmniConnect;
use Nosh\OmniConnect\Models\OmniConnectOrder;
use Nosh\OmniConnect\Support\RejectReason;
use Nosh\OmniConnect\Transport\MockTransport;

/** Mock transport that records the last URL it was asked to POST. */
class RecordingTransport extends MockTransport
{
    public ?string $lastPostUrl = null;

    public function post(string $url, array $payload, array $headers = []): array
    {
        $this->lastPostUrl = $url;

        return parent::post($url, $payload, $headers);
    }
}

/**
 * Full Laravel integration (Testbench): inbound routes + JWT middleware +
 * idempotency + events + DB, and outbound clients end-to-end over the mock.
 */
class OmniConnectTest extends TestCase
{
    private function dispatchPayload(string $token = 'ORDER-TOKEN-1'): array
    {
        return [
            'token'          => $token,
            'code'           => 'AB12',
            'expeditionType' => 'delivery',
            'currency'       => 'PKR',
            'customer'       => ['name' => 'Sara', 'mobile' => '0300'],
            'products'       => [
                ['remoteCode' => 'KARAHI', 'name' => 'Karahi', 'quantity' => 1, 'paidPrice' => '1800.00',
                    'selectedToppings' => [['remoteCode' => 'NAAN', 'name' => 'Naan', 'quantity' => 2, 'price' => '80.00']]],
            ],
            'price'   => ['grandTotal' => '1960.00'],
            'payment' => ['prepaid' => true],
        ];
    }

    public function test_dispatch_acknowledges_with_remote_order_id_and_persists(): void
    {
        Event::fake([OrderReceived::class]);

        $res = $this->withHeaders($this->middlewareAuth())
            ->postJson('omniconnect/webhooks/order/POS_REST_1', $this->dispatchPayload());

        $res->assertOk();
        $remoteOrderId = $res->json('remoteResponse.remoteOrderId');
        $this->assertNotEmpty($remoteOrderId);

        $this->assertDatabaseHas('omniconnect_orders', [
            'order_token'     => 'ORDER-TOKEN-1',
            'remote_order_id' => $remoteOrderId,
            'status'          => 'received',
            'vendor_id'       => 'POS_REST_1',
            'total'           => 196000, // paisa
        ]);
        Event::assertDispatched(OrderReceived::class);
    }

    public function test_redelivered_dispatch_is_idempotent(): void
    {
        $first = $this->withHeaders($this->middlewareAuth())
            ->postJson('omniconnect/webhooks/order/POS1', $this->dispatchPayload('DUP-1'))->json('remoteResponse.remoteOrderId');

        $second = $this->withHeaders($this->middlewareAuth())
            ->postJson('omniconnect/webhooks/order/POS1', $this->dispatchPayload('DUP-1'))->json('remoteResponse.remoteOrderId');

        $this->assertSame($first, $second);
        $this->assertSame(1, OmniConnectOrder::where('order_token', 'DUP-1')->count());
    }

    public function test_missing_token_is_rejected_400(): void
    {
        $this->withHeaders($this->middlewareAuth())
            ->postJson('omniconnect/webhooks/order/POS1', ['code' => 'X'])
            ->assertStatus(400)
            ->assertJsonPath('reason', 'VALIDATION_ERROR');
    }

    public function test_invalid_middleware_jwt_is_unauthorized(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer not-a-valid-token'])
            ->postJson('omniconnect/webhooks/order/POS1', $this->dispatchPayload())
            ->assertStatus(401);
    }

    public function test_cancellation_callback_updates_order_and_fires_event(): void
    {
        Event::fake([OrderCancelled::class]);

        $remoteOrderId = $this->withHeaders($this->middlewareAuth())
            ->postJson('omniconnect/webhooks/order/POS1', $this->dispatchPayload('CANCEL-1'))
            ->json('remoteResponse.remoteOrderId');

        $this->withHeaders($this->middlewareAuth())
            ->putJson("omniconnect/webhooks/remoteId/POS1/remoteOrder/{$remoteOrderId}/posOrderStatus", [
                'status' => 'ORDER_CANCELLED', 'message' => 'customer cancelled',
            ])->assertOk();

        $this->assertDatabaseHas('omniconnect_orders', ['order_token' => 'CANCEL-1', 'status' => 'cancelled']);
        Event::assertDispatched(OrderCancelled::class);
    }

    public function test_vendor_availability_webhook_acks(): void
    {
        $this->withHeaders($this->middlewareAuth())
            ->putJson('omniconnect/webhooks/remoteId/POS1/availability', ['timestamp' => now()->toIso8601String(), 'closures' => []])
            ->assertOk();
    }

    public function test_menu_import_trigger_carries_vendor_code_and_import_id(): void
    {
        Event::fake([MenuImportRequested::class]);

        $this->withHeaders($this->middlewareAuth())
            ->getJson('omniconnect/webhooks/menuimport/POS1?vendorCode=VENDOR_CODE_0001&menuImportId=MENU_ID_007')
            ->assertStatus(202);

        Event::assertDispatched(MenuImportRequested::class, fn (MenuImportRequested $e) =>
            $e->remoteId === 'POS1' && $e->vendorCode === 'VENDOR_CODE_0001' && $e->menuImportId === 'MENU_ID_007');
    }

    public function test_product_modification_result_surfaces_without_clobbering_status(): void
    {
        Event::fake([OrderStatusUpdated::class]);

        $remoteOrderId = $this->withHeaders($this->middlewareAuth())
            ->postJson('omniconnect/webhooks/order/POS1', $this->dispatchPayload('MOD-1'))
            ->json('remoteResponse.remoteOrderId');

        $this->withHeaders($this->middlewareAuth())
            ->putJson("omniconnect/webhooks/remoteId/POS1/remoteOrder/{$remoteOrderId}/posOrderStatus", [
                'status' => 'PRODUCT_ORDER_MODIFICATION_FAILED', 'message' => 'UNKNOWN_PRODUCT_ID',
            ])->assertOk();

        // Event carries the raw enum + error code; order stays 'received'.
        Event::assertDispatched(OrderStatusUpdated::class, fn (OrderStatusUpdated $e) =>
            $e->rawStatus === 'PRODUCT_ORDER_MODIFICATION_FAILED' && $e->message === 'UNKNOWN_PRODUCT_ID');
        $this->assertDatabaseHas('omniconnect_orders', ['order_token' => 'MOD-1', 'status' => 'received']);
    }

    // ---- Outbound over the mock ------------------------------------------

    public function test_outbound_accept_fires_event_and_advances_row(): void
    {
        Event::fake([OrderAccepted::class]);
        OmniConnectOrder::create(['platform' => 'delivery_hero', 'order_token' => 'OUT-1', 'status' => 'received']);

        $body = OmniConnect::orders()->accept('OUT-1');

        $this->assertStringContainsString('successfully', $body['message'] ?? '');
        $this->assertDatabaseHas('omniconnect_orders', ['order_token' => 'OUT-1', 'status' => 'accepted']);
        Event::assertDispatched(OrderAccepted::class);
    }

    public function test_outbound_reject_requires_valid_reason(): void
    {
        $this->expectExceptionMessage('Invalid rejection reason');
        OmniConnect::orders()->reject('OUT-2', 'NOT_A_REAL_REASON');
    }

    public function test_outbound_reject_with_valid_reason_succeeds(): void
    {
        $body = OmniConnect::orders()->reject('OUT-3', RejectReason::ITEM_UNAVAILABLE, ['message' => 'sold out']);
        $this->assertStringContainsString('successfully', $body['message'] ?? '');
    }

    public function test_accept_targets_the_per_order_callback_url_when_present(): void
    {
        $recorder = new RecordingTransport();
        $this->app->instance(Transport::class, $recorder);

        // Dispatch an order carrying its own accept callback URL.
        $payload = $this->dispatchPayload('CB-1');
        $payload['callbackUrls'] = ['orderAcceptedUrl' => 'https://dh.example/cb/accept/CB-1'];
        $this->withHeaders($this->middlewareAuth())->postJson('omniconnect/webhooks/order/POS1', $payload)->assertOk();

        OmniConnect::orders()->accept('CB-1');
        $this->assertSame('https://dh.example/cb/accept/CB-1', $recorder->lastPostUrl);

        // An order without callbackUrls falls back to the constructed status path.
        OmniConnectOrder::create(['platform' => 'delivery_hero', 'order_token' => 'CB-2', 'status' => 'received']);
        OmniConnect::orders()->accept('CB-2');
        $this->assertStringEndsWith('/v2/order/status/CB-2', $recorder->lastPostUrl);
    }

    public function test_catalog_store_and_reports_clients_work(): void
    {
        $this->assertArrayHasKey('catalogImportId', OmniConnect::catalog()->submitCatalog(['items' => ['x' => []]]));
        $this->assertSame([], OmniConnect::catalog()->enableItems('POS1', ['i1'], 'FP_PK'));
        $this->assertSame('FP_PK', OmniConnect::store()->getAvailability('POS1')[0]['platformKey']);
        $this->assertArrayHasKey('orderIdentifiers', OmniConnect::reports()->orderIds('accepted', 6));
    }
}
