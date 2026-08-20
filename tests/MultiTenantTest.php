<?php

namespace Nosh\OmniConnect\Tests;

use Nosh\OmniConnect\Contracts\Transport;
use Nosh\OmniConnect\Facades\OmniConnect;
use Nosh\OmniConnect\Models\OmniConnectCredential;
use Nosh\OmniConnect\Models\OmniConnectOrder;
use Nosh\OmniConnect\OmniConnectServiceProvider;
use Nosh\OmniConnect\Support\Jwt;
use Nosh\OmniConnect\Transport\MockTransport;
use Orchestra\Testbench\TestCase as Orchestra;

/** Mock transport that remembers which vendor authenticated at /v2/login. */
class LoginRecordingTransport extends MockTransport
{
    public ?string $lastLoginUser = null;

    public function postForm(string $url, array $params, array $headers = []): array
    {
        if (str_contains($url, '/v2/login')) {
            $this->lastLoginUser = $params['username'] ?? null;
        }

        return parent::postForm($url, $params, $headers);
    }
}

/**
 * Proves the package is truly multi-tenant: many restaurants, each with its own
 * foodpanda account + webhook secret, fully isolated from the others.
 */
class MultiTenantTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [OmniConnectServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        $app['config']->set('omniconnect.transport', 'mock');
        // Multi-tenant: per-restaurant rows, NO global webhook secret.
        $app['config']->set('omniconnect.credentials.driver', 'database');
        $app['config']->set('omniconnect.webhook.secret', null);
        $app['config']->set('omniconnect.webhook.verify_signature', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        OmniConnectCredential::create([
            'tenant_id' => 'rest-A', 'platform' => 'delivery_hero', 'sandbox' => true,
            'username' => 'userA', 'password' => 'passA', 'chain_code' => 'CHAIN_A',
            'vendor_ids' => ['POS_A_1'], 'webhook_secret' => 'secret-A',
        ]);
        OmniConnectCredential::create([
            'tenant_id' => 'rest-B', 'platform' => 'delivery_hero', 'sandbox' => true,
            'username' => 'userB', 'password' => 'passB', 'chain_code' => 'CHAIN_B',
            'vendor_ids' => ['POS_B_1'], 'webhook_secret' => 'secret-B',
        ]);
    }

    private function auth(string $secret): array
    {
        return ['Authorization' => 'Bearer '.Jwt::encode(['service' => 'middleware', 'exp' => time() + 300], $secret)];
    }

    private function payload(string $token): array
    {
        return [
            'token' => $token, 'code' => 'AB12', 'expeditionType' => 'delivery',
            'customer' => ['firstName' => 'A', 'lastName' => 'B', 'mobilePhone' => '0300'],
            'products' => [['remoteCode' => 'X', 'name' => 'X', 'quantity' => 1, 'paidPrice' => '100.00']],
            'price' => ['grandTotal' => '100.00'], 'payment' => ['status' => 'paid'],
        ];
    }

    public function test_credentials_resolve_per_tenant(): void
    {
        $this->assertSame('userA', OmniConnect::for('rest-A')->credentials()->username);
        $this->assertSame('CHAIN_A', OmniConnect::for('rest-A')->credentials()->chainCode);
        $this->assertSame('userB', OmniConnect::for('rest-B')->credentials()->username);

        // Token caches are keyed per tenant → no cross-account bleed.
        $this->assertNotSame(
            OmniConnect::for('rest-A')->credentials()->tokenCacheKey(),
            OmniConnect::for('rest-B')->credentials()->tokenCacheKey(),
        );
    }

    public function test_inbound_maps_remote_id_to_the_owning_tenant(): void
    {
        $this->assertSame('rest-A', OmniConnect::credentialsForRemoteId('POS_A_1')?->tenantId);
        $this->assertSame('rest-B', OmniConnect::credentialsForRemoteId('POS_B_1')?->tenantId);
        $this->assertNull(OmniConnect::credentialsForRemoteId('POS_UNKNOWN'));
    }

    public function test_webhook_verified_against_that_tenants_secret_and_tagged(): void
    {
        // Signed with tenant A's secret, addressed to A's vendor → accepted + tagged.
        $this->withHeaders($this->auth('secret-A'))
            ->postJson('omniconnect/webhooks/order/POS_A_1', $this->payload('MT-A1'))
            ->assertOk();

        $this->assertDatabaseHas('omniconnect_orders', ['order_token' => 'MT-A1', 'tenant_id' => 'rest-A', 'vendor_id' => 'POS_A_1']);
    }

    public function test_webhook_signed_with_a_different_tenants_secret_is_rejected(): void
    {
        // B's secret cannot authorize a call to A's vendor.
        $this->withHeaders($this->auth('secret-B'))
            ->postJson('omniconnect/webhooks/order/POS_A_1', $this->payload('MT-A2'))
            ->assertStatus(401);

        $this->assertDatabaseMissing('omniconnect_orders', ['order_token' => 'MT-A2']);
    }

    public function test_unknown_vendor_fails_closed(): void
    {
        $this->withHeaders($this->auth('secret-A'))
            ->postJson('omniconnect/webhooks/order/POS_UNKNOWN', $this->payload('MT-X'))
            ->assertStatus(401);
    }

    public function test_outbound_authenticates_as_the_selected_tenant(): void
    {
        $recorder = new LoginRecordingTransport();
        $this->app->instance(Transport::class, $recorder);

        OmniConnect::for('rest-B')->orders()->accept('SOME-ORDER');
        $this->assertSame('userB', $recorder->lastLoginUser, 'Outbound call must authenticate as tenant B.');
    }
}
