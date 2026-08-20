<?php

namespace Nosh\OmniConnect\Tests;

use Nosh\OmniConnect\OmniConnectServiceProvider;
use Nosh\OmniConnect\Support\Jwt;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $secret = 'test-webhook-secret';

    protected function getPackageProviders($app): array
    {
        return [OmniConnectServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('omniconnect.transport', 'mock');
        $app['config']->set('omniconnect.credentials.driver', 'env');
        $app['config']->set('omniconnect.vendor.username', 'demo-user');
        $app['config']->set('omniconnect.vendor.password', 'demo-pass');
        $app['config']->set('omniconnect.vendor.chain_code', 'CHAIN1');
        $app['config']->set('omniconnect.webhook.secret', $this->secret);
        $app['config']->set('omniconnect.webhook.verify_signature', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /** A valid Middleware JWT header, as Delivery Hero would send. */
    protected function middlewareAuth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::encode(
            ['service' => 'middleware', 'exp' => time() + 300],
            $this->secret
        )];
    }
}
