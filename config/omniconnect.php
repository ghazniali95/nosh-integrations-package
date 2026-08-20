<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default delivery platform
    |--------------------------------------------------------------------------
    | Which platform a vendor integrates with when none is specified.
    | delivery_hero  (foodpanda)  is the first driver; others plug in below.
    */
    'default' => env('OMNICONNECT_PLATFORM', 'delivery_hero'),

    /*
    |--------------------------------------------------------------------------
    | Sandbox switch
    |--------------------------------------------------------------------------
    | Global default. Per-tenant credentials can override this.
    | true  = talk to the platform's staging/sandbox host
    | false = production
    */
    'sandbox' => env('OMNICONNECT_SANDBOX', true),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    | 'http' = real HTTPS calls to the platform's Integration Middleware.
    | 'mock' = an in-package fake Delivery Hero returning spec-shaped responses,
    |          so the whole flow (login, accept/reject, catalog, store) is
    |          testable with NO credentials. Great for local dev + demos.
    */
    'transport' => env('OMNICONNECT_TRANSPORT', 'http'),

    /*
    |--------------------------------------------------------------------------
    | Vendor identity (single-install)
    |--------------------------------------------------------------------------
    | Used when credentials.driver = 'env'. In multi-tenant mode these come
    | per-tenant from the omniconnect_credentials table (or your own resolver).
    |
    | username/password are the client_credentials issued via Delivery Hero's
    | "Get Credentials" developer flow; the package exchanges them at /v2/login
    | for a short-lived (30 min) JWT and caches it.
    */
    'vendor' => [
        'username'   => env('OMNICONNECT_USERNAME'),
        'password'   => env('OMNICONNECT_PASSWORD'),
        'chain_code' => env('OMNICONNECT_CHAIN_CODE'),
        // Comma-separated list of platform vendor/store ids this install owns.
        'vendor_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('OMNICONNECT_VENDOR_IDS', ''))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials resolution
    |--------------------------------------------------------------------------
    | 'env'      → use the vendor.* block above (one restaurant/chain)
    | 'database' → look up per tenant from the omniconnect_credentials table
    | 'callback' → resolve via a closure (OmniConnect::resolveCredentialsUsing)
    */
    'credentials' => [
        'driver'  => env('OMNICONNECT_CREDENTIALS_DRIVER', 'env'),
        'encrypt' => true, // password/token encrypted at rest in the database driver
    ],

    /*
    |--------------------------------------------------------------------------
    | Access-token cache (Login API returns a 30-min JWT)
    |--------------------------------------------------------------------------
    | The package logs in once and reuses the JWT until it nears expiry, then
    | transparently re-logs-in (and on a 401 it forces a refresh + one retry).
    */
    'token' => [
        'cache_store' => env('OMNICONNECT_TOKEN_CACHE'), // null = default cache store
        'ttl_buffer'  => (int) env('OMNICONNECT_TOKEN_TTL_BUFFER', 120), // refresh this many secs before expiry
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound Plugin API (webhooks Delivery Hero calls on US)
    |--------------------------------------------------------------------------
    | The package registers routes under this prefix to receive order dispatch,
    | order status updates, and menu-import requests. Set `register_routes` =
    | false to mount them yourself.
    |
    | `secret` is the shared secret Delivery Hero signs its JWTs with, for a
    | SINGLE-install (env) deployment. In MULTI-TENANT mode (credentials.driver
    | = 'database'), the secret is resolved PER restaurant from its
    | omniconnect_credentials.webhook_secret by matching the inbound {remoteId} — this
    | value is ignored, and an unknown vendor is rejected (fail-closed).
    */
    'webhook' => [
        'register_routes' => (bool) env('OMNICONNECT_WEBHOOK_ROUTES', true),
        'route_prefix'    => env('OMNICONNECT_WEBHOOK_PREFIX', 'omniconnect/webhooks'),
        'middleware'      => ['api'],
        'secret'          => env('OMNICONNECT_WEBHOOK_SECRET'),
        'verify_signature'=> (bool) env('OMNICONNECT_WEBHOOK_VERIFY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reliability & queue
    |--------------------------------------------------------------------------
    */
    'timeout'        => (int) env('OMNICONNECT_TIMEOUT', 30),
    'retry_attempts' => (int) env('OMNICONNECT_RETRY_ATTEMPTS', 3),
    'retry_backoff'  => [10, 30, 120], // seconds between async retries
    'queue'          => env('OMNICONNECT_QUEUE', 'omniconnect'),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => (bool) env('OMNICONNECT_LOGGING_ENABLED', true),
        'channel' => env('OMNICONNECT_LOG_CHANNEL', 'daily'),
        'level'   => env('OMNICONNECT_LOG_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Platforms
    |--------------------------------------------------------------------------
    | One block per delivery platform. Hosts are pre-filled from the platform's
    | official spec; you normally only supply the credentials (env or database).
    |
    | Delivery Hero: username/password → JWT at /v2/login; Bearer on every call.
    | Staging host confirmed from the Middleware OpenAPI spec. Production host is
    | the same domain without the `.stg` segment — CONFIRM against your onboarding.
    */
    'platforms' => [

        'delivery_hero' => [
            'label'  => 'Delivery Hero (foodpanda)',
            'driver' => \Nosh\OmniConnect\Drivers\DeliveryHeroDriver::class,
            'hosts'  => [
                'sandbox'    => env('OMNICONNECT_DH_SANDBOX_HOST', 'https://integration-middleware.stg.restaurant-partners.com'),
                'production' => env('OMNICONNECT_DH_PRODUCTION_HOST', 'https://integration-middleware.restaurant-partners.com'),
            ],
        ],

        // Future platforms share the DeliveryPlatformDriver contract and get
        // their own driver + hosts as each spec is onboarded.
        'careem' => [
            'label'  => 'Careem (Now / Food)',
            'driver' => null, // 🚧 rolling out
            'hosts'  => [],
        ],
    ],
];
