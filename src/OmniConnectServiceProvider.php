<?php

namespace Nosh\OmniConnect;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nosh\OmniConnect\Console\SimulateOrderCommand;

class OmniConnectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/omniconnect.php', 'omniconnect');

        $this->app->singleton('omniconnect', function ($app) {
            return new OmniConnectManager($app, $app['config']->get('omniconnect'));
        });

        $this->app->alias('omniconnect', OmniConnectManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/omniconnect.php' => $this->configPath('omniconnect.php'),
        ], 'omniconnect-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => $this->databasePath('migrations'),
        ], 'omniconnect-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerWebhookRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                SimulateOrderCommand::class,
            ]);
        }
    }

    /**
     * Mount the inbound Plugin API (webhooks Delivery Hero calls on us) under
     * the configured prefix + middleware, unless the host opts out.
     */
    protected function registerWebhookRoutes(): void
    {
        $config = $this->app['config']->get('omniconnect.webhook', []);
        $routesFile = __DIR__.'/Http/routes.php';

        if (! ($config['register_routes'] ?? true) || ! file_exists($routesFile)) {
            return;
        }

        Route::group([
            'prefix'     => $config['route_prefix'] ?? 'omniconnect/webhooks',
            'middleware' => $config['middleware'] ?? ['api'],
        ], function () use ($routesFile) {
            require $routesFile;
        });
    }

    protected function configPath(string $path): string
    {
        return function_exists('config_path') ? config_path($path) : base_path('config/'.$path);
    }

    protected function databasePath(string $path): string
    {
        return function_exists('database_path') ? database_path($path) : base_path('database/'.$path);
    }
}
