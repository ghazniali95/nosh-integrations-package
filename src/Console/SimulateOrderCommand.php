<?php

namespace Nosh\OmniConnect\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nosh\OmniConnect\Support\Jwt;

/**
 * Local "fake Delivery Hero": signs a Middleware JWT and POSTs a real
 * dispatch-order payload at our own inbound Plugin-API route, so the whole
 * receive → acknowledge → (cancel) loop is demoable with NO real platform.
 *
 *   php artisan omniconnect:simulate-order
 *   php artisan omniconnect:simulate-order POS_RESTAURANT_0001 --cancel
 */
class SimulateOrderCommand extends Command
{
    protected $signature = 'omniconnect:simulate-order
        {remoteId=POS_RESTAURANT_0001 : POS-side restaurant id}
        {--base= : Base URL of the running app (default config app.url)}
        {--cancel : Also simulate a cancellation callback}';

    protected $description = 'Simulate Delivery Hero dispatching an order to this app (inbound Plugin API).';

    public function handle(): int
    {
        $config = config('omniconnect');
        $base = rtrim($this->option('base') ?: (config('app.url') ?: 'http://127.0.0.1:8000'), '/');
        $prefix = trim($config['webhook']['route_prefix'] ?? 'omniconnect/webhooks', '/');
        $remoteId = $this->argument('remoteId');
        $secret = $config['webhook']['secret'] ?? null;

        $headers = ['Accept' => 'application/json'];
        if (! empty($secret)) {
            // Mirror what Delivery Hero sends: HS512 JWT with service=middleware.
            $headers['Authorization'] = 'Bearer '.Jwt::encode(
                ['service' => 'middleware', 'iat' => time(), 'exp' => time() + 300],
                $secret
            );
            $this->line('Signed a Middleware JWT (service=middleware) with the configured secret.');
        } else {
            $this->line('No webhook secret configured → sending unauthenticated (accepted only on the mock transport).');
        }

        $payload = $this->sampleOrder();
        $dispatchUrl = "{$base}/{$prefix}/order/{$remoteId}";

        $this->line("POST {$dispatchUrl}");
        $res = Http::withHeaders($headers)->acceptJson()->post($dispatchUrl, $payload);

        $this->line('→ '.$res->status().' '.json_encode($res->json()));
        $remoteOrderId = $res->json('remoteResponse.remoteOrderId');

        if (! $res->successful() || ! $remoteOrderId) {
            $this->error('Dispatch did not acknowledge a remoteOrderId.');

            return self::FAILURE;
        }
        $this->info("Order received. Our remoteOrderId = {$remoteOrderId}");

        if ($this->option('cancel')) {
            $statusUrl = "{$base}/{$prefix}/remoteId/{$remoteId}/remoteOrder/{$remoteOrderId}/posOrderStatus";
            $this->line("PUT {$statusUrl}  (ORDER_CANCELLED)");
            $cancel = Http::withHeaders($headers)->acceptJson()->put($statusUrl, [
                'status'  => 'ORDER_CANCELLED',
                'message' => 'Customer cancelled (simulated).',
            ]);
            $this->line('→ '.$cancel->status().' '.json_encode($cancel->json()));
        }

        return self::SUCCESS;
    }

    /** A dispatch payload shaped like the plugin spec's order example. */
    protected function sampleOrder(): array
    {
        $token = (string) Str::uuid();

        return [
            'token'              => $token,
            'code'               => strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)),
            'comments'           => ['customerComment' => 'Please hurry, I am hungry'],
            'createdAt'          => now()->toIso8601ZuluString(),
            'expeditionType'     => 'delivery',
            'test'               => false,
            'customer'           => ['firstName' => 'Ahsan', 'lastName' => 'Raza', 'mobilePhone' => '+92 300 0000000'],
            'delivery'           => ['address' => [], 'riderPickupTime' => null, 'expectedDeliveryTime' => now()->addMinutes(40)->toIso8601ZuluString()],
            'localInfo'          => ['countryCode' => 'PK', 'currencySymbol' => 'Rs', 'platform' => 'Foodpanda', 'platformKey' => 'FP_PK'],
            'platformRestaurant' => ['id' => 'sq-'.Str::lower(Str::random(4))],
            'products'           => [
                [
                    'id' => 'PLAT-1', 'remoteCode' => 'KARAHI-FULL', 'categoryName' => 'Karahi',
                    'name' => 'Chicken Karahi (Full)', 'quantity' => 1, 'paidPrice' => '1800.00',
                    'selectedToppings' => [
                        ['id' => 'T-1', 'remoteCode' => 'EXTRA-NAAN', 'name' => 'Extra Naan', 'type' => 'EXTRA', 'quantity' => 2, 'price' => '80.00', 'children' => []],
                    ],
                ],
                [
                    'id' => 'PLAT-2', 'remoteCode' => 'SOFTDRINK', 'categoryName' => 'Drinks',
                    'name' => 'Soft Drink', 'quantity' => 2, 'paidPrice' => '120.00', 'selectedToppings' => [],
                ],
            ],
            'price'   => ['grandTotal' => '2200.00', 'totalNet' => '1950.00', 'vatTotal' => '250.00'],
            'payment' => ['status' => 'paid', 'type' => 'paid'],
        ];
    }
}
