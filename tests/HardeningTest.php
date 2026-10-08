<?php

namespace Nosh\OmniConnect\Tests;

use Illuminate\Support\Facades\Queue;
use Nosh\OmniConnect\Contracts\IncomingOrderHandler;
use Nosh\OmniConnect\Data\Order;
use Nosh\OmniConnect\Models\OmniConnectOrder;
use Nosh\OmniConnect\Orders\AcceptOrderJob;
use RuntimeException;

/** A handler whose behaviour each test sets, and which counts what it saw. */
class ScriptedHandler implements IncomingOrderHandler
{
    public static int $handled = 0;
    public static int $cancelled = 0;
    public static bool $failNext = false;
    public static ?string $intent = null;
    public static ?Order $last = null;

    public static function reset(): void
    {
        self::$handled = 0;
        self::$cancelled = 0;
        self::$failNext = false;
        self::$intent = null;
        self::$last = null;
    }

    public function handle(Order $order): ?string
    {
        if (self::$failNext) {
            self::$failNext = false;
            throw new RuntimeException('app was down');
        }
        self::$handled++;
        self::$last = $order;

        return self::$intent;
    }

    public function cancelled(Order $order): void
    {
        self::$cancelled++;
    }
}

/**
 * v1.1.0 fixes: retried handoff, queued auto-accept, fail-closed webhook auth,
 * vendor-scoped status updates, and the richer dispatch parse.
 */
class HardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ScriptedHandler::reset();
        $this->app->bind(IncomingOrderHandler::class, ScriptedHandler::class);
    }

    private function payload(string $token = 'T-1'): array
    {
        return [
            'token'          => $token,
            'code'           => 'AB12',
            'expeditionType' => 'delivery',
            'preOrder'       => true,
            'platformRestaurant' => ['id' => 'fp-vendor-9'],
            'customer'       => ['firstName' => 'Sara', 'lastName' => 'Khan', 'mobilePhone' => '+92 300 1111111'],
            'delivery'       => [
                'address' => [
                    'flatNumber' => 'Flat 4', 'building' => 'Rose Heights', 'number' => '12', 'street' => 'MM Alam Rd',
                    'city' => 'Lahore', 'latitude' => 31.51, 'longitude' => 74.35, 'deliveryInstructions' => 'Ring twice',
                ],
                'expectedDeliveryTime' => '2026-10-08T15:40:00Z',
                'riderPickupTime'      => '2026-10-08T15:20:00Z',
            ],
            'products' => [
                ['id' => 'FP-111', 'remoteCode' => '', 'name' => 'Zinger', 'quantity' => 2, 'paidPrice' => '650.00',
                    'discountAmount' => '50.00', 'variation' => ['name' => 'Large'],
                    'selectedToppings' => [['id' => 'FP-T1', 'name' => 'Cheese', 'quantity' => 1, 'price' => '60.00']]],
            ],
            'discounts' => [
                ['name' => 'Panda Pro', 'amount' => '100.00', 'type' => 'PERCENTAGE', 'sponsor' => 'PLATFORM'],
                ['name' => 'House deal', 'amount' => '50.00', 'sponsor' => 'VENDOR'],
            ],
            'price' => [
                'subTotal' => '1360.00', 'totalNet' => '1180.00', 'grandTotal' => '1310.00',
                'containerCharge' => '20.00', 'riderTip' => '30.00', 'collectFromCustomer' => '1310.00', 'payRestaurant' => '1000.00',
            ],
            'payment' => ['status' => 'pending', 'type' => 'cash'],
        ];
    }

    private function dispatch(string $remoteId = 'POS1', ?array $payload = null)
    {
        return $this->withHeaders($this->middlewareAuth())
            ->postJson("omniconnect/webhooks/order/{$remoteId}", $payload ?? $this->payload());
    }

    // ---- Retried handoff --------------------------------------------------

    public function test_a_failed_handoff_is_retried_on_redelivery_not_silently_acked(): void
    {
        $this->withoutExceptionHandling();
        ScriptedHandler::$failNext = true;

        try {
            $this->dispatch();
            $this->fail('The handler failure must reach the platform as an error.');
        } catch (RuntimeException) {
            // the platform sees a 5xx and will re-send
        }

        $this->assertNull(OmniConnectOrder::first()->handled_at);
        $this->assertSame(0, ScriptedHandler::$handled);

        $first = $this->dispatch()->assertOk()->json('remoteResponse.remoteOrderId');
        $this->assertSame(1, ScriptedHandler::$handled, 'the re-delivery must hand the order over');
        $this->assertNotNull(OmniConnectOrder::first()->handled_at);

        $again = $this->dispatch()->json('remoteResponse.remoteOrderId');
        $this->assertSame(1, ScriptedHandler::$handled, 'once handled, a re-delivery is only acked');
        $this->assertSame($first, $again);
        $this->assertSame(1, OmniConnectOrder::count());
    }

    // ---- Queued auto-accept ----------------------------------------------

    public function test_auto_accept_is_queued_not_made_inside_the_webhook(): void
    {
        Queue::fake();
        ScriptedHandler::$intent = 'accepted';

        $this->dispatch()->assertOk();

        Queue::assertPushed(AcceptOrderJob::class, fn (AcceptOrderJob $j) => $j->orderToken === 'T-1');
        $this->assertSame('received', OmniConnectOrder::first()->status);
    }

    public function test_the_queued_accept_accepts_the_order(): void
    {
        ScriptedHandler::$intent = 'accepted';   // sync queue in tests: the job runs now

        $this->dispatch()->assertOk();

        $this->assertSame('accepted', OmniConnectOrder::first()->status);
    }

    // ---- Fail-closed auth -------------------------------------------------

    public function test_a_blank_secret_on_the_live_transport_is_unauthorized(): void
    {
        config(['omniconnect.webhook.secret' => null, 'omniconnect.transport' => 'http']);
        $this->refreshManager();

        $this->postJson('omniconnect/webhooks/order/POS1', $this->payload())->assertStatus(401);
        $this->assertSame(0, OmniConnectOrder::count());
    }

    public function test_a_blank_secret_on_the_mock_transport_is_local_dev(): void
    {
        config(['omniconnect.webhook.secret' => null, 'omniconnect.transport' => 'mock']);
        $this->refreshManager();

        $this->postJson('omniconnect/webhooks/order/POS1', $this->payload())->assertOk();
    }

    // ---- Vendor-scoped status updates ------------------------------------

    public function test_a_status_update_under_another_vendor_cannot_cancel_the_order(): void
    {
        $remoteOrderId = $this->dispatch('POS1')->json('remoteResponse.remoteOrderId');

        $this->withHeaders($this->middlewareAuth())
            ->putJson("omniconnect/webhooks/remoteId/POS2/remoteOrder/{$remoteOrderId}/posOrderStatus", ['status' => 'ORDER_CANCELLED'])
            ->assertOk();

        $this->assertSame('received', OmniConnectOrder::first()->status);
        $this->assertSame(0, ScriptedHandler::$cancelled);

        $this->withHeaders($this->middlewareAuth())
            ->putJson("omniconnect/webhooks/remoteId/POS1/remoteOrder/{$remoteOrderId}/posOrderStatus", ['status' => 'ORDER_CANCELLED'])
            ->assertOk();

        $this->assertSame('cancelled', OmniConnectOrder::first()->status);
        $this->assertSame(1, ScriptedHandler::$cancelled);
    }

    public function test_a_cancel_for_an_unknown_order_does_not_reach_the_handler(): void
    {
        $this->withHeaders($this->middlewareAuth())
            ->putJson('omniconnect/webhooks/remoteId/POS1/remoteOrder/PANDA-999/posOrderStatus', ['status' => 'ORDER_CANCELLED'])
            ->assertOk();

        $this->assertSame(0, ScriptedHandler::$cancelled);
    }

    // ---- Richer parse -----------------------------------------------------

    public function test_dispatch_carries_money_timing_address_and_line_keys(): void
    {
        $this->dispatch('POS1')->assertOk();
        $o = ScriptedHandler::$last;

        $this->assertSame('POS1', $o->remoteId);
        $this->assertSame('fp-vendor-9', $o->vendorId);
        $this->assertSame(136000, $o->subtotal, 'subTotal, not the ex-VAT totalNet');
        $this->assertSame(15000, $o->discountTotal);
        $this->assertSame('platform', $o->discounts[0]['sponsor']);
        $this->assertSame('vendor', $o->discounts[1]['sponsor']);
        $this->assertSame(2000, $o->containerCharge);
        $this->assertSame(3000, $o->riderTip);
        $this->assertSame(131000, $o->collectFromCustomer);
        $this->assertSame(100000, $o->payRestaurant);
        $this->assertSame('cash', $o->paymentType);
        $this->assertFalse($o->paidOnline);
        $this->assertTrue($o->preOrder);
        $this->assertSame('2026-10-08T15:20:00Z', $o->riderPickupTime);
        $this->assertSame('2026-10-08T15:40:00Z', $o->expectedDeliveryTime);

        $this->assertSame('Sara Khan', $o->customer->name);
        $this->assertStringContainsString('Rose Heights', $o->customer->address);
        $this->assertStringContainsString('Ring twice', $o->customer->address);
        $this->assertSame(31.51, $o->customer->lat);

        $line = $o->items[0];
        $this->assertSame('FP-111', $line->platformId);
        $this->assertNull($line->remoteCode, 'a blank remoteCode is no code');
        $this->assertSame('Large', $line->variation);
        $this->assertSame(5000, $line->discount);
        $this->assertSame('FP-T1', $line->modifiers[0]->platformId);
    }

    /** The manager singleton captured config at boot; rebuild it after a config() change. */
    private function refreshManager(): void
    {
        $this->app->forgetInstance('omniconnect');
        \Nosh\OmniConnect\Facades\OmniConnect::clearResolvedInstance('omniconnect');
    }
}
