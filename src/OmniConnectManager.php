<?php

namespace Nosh\OmniConnect;

use Closure;
use Illuminate\Contracts\Container\Container;
use Nosh\OmniConnect\Contracts\CredentialResolver;
use Nosh\OmniConnect\Contracts\DeliveryPlatformDriver;
use Nosh\OmniConnect\Contracts\Transport;
use Nosh\OmniConnect\Credentials\CallbackCredentialResolver;
use Nosh\OmniConnect\Credentials\DatabaseCredentialResolver;
use Nosh\OmniConnect\Credentials\EnvCredentialResolver;
use Nosh\OmniConnect\Data\Credentials;
use Nosh\OmniConnect\Catalog\CatalogClient;
use Nosh\OmniConnect\Events\CredentialsMissing;
use Nosh\OmniConnect\Exceptions\CredentialsMissingException;
use Nosh\OmniConnect\Exceptions\OmniConnectException;
use Nosh\OmniConnect\Http\AuthorizedClient;
use Nosh\OmniConnect\Orders\OrderClient;
use Nosh\OmniConnect\Reports\ReportClient;
use Nosh\OmniConnect\Store\StoreClient;
use Nosh\OmniConnect\Transport\HttpTransport;
use Nosh\OmniConnect\Transport\MockTransport;
use Nosh\OmniConnect\Transport\TokenManager;

/**
 * The class behind the OmniConnect facade.
 *
 * Fluent context (platform/tenant/sandbox) is applied by cloning, so calls
 * never leak state:
 *   OmniConnect::orders()->accept($token)
 *   OmniConnect::for($restaurant)->orders()->reject($token, $reason)
 *   OmniConnect::platform('delivery_hero')->sandbox()->orders()->markPrepared($token)
 */
class OmniConnectManager
{
    protected ?string $platform = null;
    protected mixed $tenant = null;
    protected ?bool $sandboxOverride = null;
    protected ?Closure $credentialCallback = null;
    protected ?Closure $inboundResolver = null;

    public function __construct(
        protected Container $container,
        protected array $config,
    ) {
    }

    // ---- Fluent context ---------------------------------------------------

    public function platform(string $platform): static
    {
        $clone = clone $this;
        $clone->platform = $platform;

        return $clone;
    }

    public function for(mixed $tenant): static
    {
        $clone = clone $this;
        $clone->tenant = $tenant;

        return $clone;
    }

    public function sandbox(bool $on = true): static
    {
        $clone = clone $this;
        $clone->sandboxOverride = $on;

        return $clone;
    }

    public function resolveCredentialsUsing(Closure $callback): void
    {
        $this->credentialCallback = $callback;
    }

    /**
     * Register how an INBOUND webhook's {remoteId} (platform vendor id) maps to
     * a tenant's credentials. The closure receives ($remoteId, $platform) and
     * may return a Credentials, an array, a OmniConnectCredential (or anything with
     * toCredentials()), a tenant id (string/int), or null.
     */
    public function resolveInboundUsing(Closure $callback): void
    {
        $this->inboundResolver = $callback;
    }

    public function hasInboundResolver(): bool
    {
        return $this->inboundResolver !== null;
    }

    /**
     * Resolve which restaurant an inbound webhook belongs to, from its
     * {remoteId}. Used to pick the right webhook secret (verification) and
     * account (auto-accept, tagging). Falls back to the single-install env
     * credentials when not multi-tenant.
     */
    public function credentialsForRemoteId(string $remoteId, ?string $platform = null): ?Credentials
    {
        $platform ??= $this->platform ?? ($this->config['default'] ?? 'delivery_hero');

        if ($this->inboundResolver) {
            return $this->normalizeInbound(($this->inboundResolver)($remoteId, $platform));
        }

        if (($this->config['credentials']['driver'] ?? 'env') === 'database') {
            return \Nosh\OmniConnect\Models\OmniConnectCredential::query()
                ->forRemoteId($remoteId, $platform)
                ->first()?->toCredentials();
        }

        // Single-install: env credentials carry the (single) webhook secret.
        return (new EnvCredentialResolver($this->config))->resolve(null, $platform);
    }

    protected function normalizeInbound(mixed $result): ?Credentials
    {
        if ($result === null || $result instanceof Credentials) {
            return $result;
        }
        if (is_array($result)) {
            return Credentials::fromArray($result);
        }
        if (is_object($result) && method_exists($result, 'toCredentials')) {
            return $result->toCredentials();
        }
        if (is_string($result) || is_int($result)) {
            return (new DatabaseCredentialResolver($this->config))->resolve($result, $this->platform);
        }

        return null;
    }

    // ---- Domain clients ---------------------------------------------------

    public function orders(?Credentials $credentials = null): OrderClient
    {
        $credentials ??= $this->credentials();

        return new OrderClient($this->api($credentials), $this->driver($credentials), $credentials);
    }

    public function catalog(?Credentials $credentials = null): CatalogClient
    {
        $credentials ??= $this->credentials();

        return new CatalogClient($this->api($credentials), $this->driver($credentials), $credentials);
    }

    public function store(?Credentials $credentials = null): StoreClient
    {
        $credentials ??= $this->credentials();

        return new StoreClient($this->api($credentials), $this->driver($credentials), $credentials);
    }

    public function reports(?Credentials $credentials = null): ReportClient
    {
        $credentials ??= $this->credentials();

        return new ReportClient($this->api($credentials), $this->driver($credentials), $credentials);
    }

    /** Build the shared authenticated HTTP client for a set of credentials. */
    public function api(Credentials $credentials): AuthorizedClient
    {
        return new AuthorizedClient(
            $this->driver($credentials),
            $this->transport(),
            $this->tokenManager(),
            $credentials,
        );
    }

    // ---- Assembly ---------------------------------------------------------

    public function driver(?Credentials $credentials = null): DeliveryPlatformDriver
    {
        $credentials ??= $this->credentials();
        $platformKey = $credentials->platform;
        $platformConfig = $this->config['platforms'][$platformKey] ?? null;

        if (! $platformConfig) {
            throw new OmniConnectException("Unknown delivery platform [{$platformKey}].");
        }

        $driverClass = $platformConfig['driver'] ?? null;
        if (! $driverClass) {
            throw new OmniConnectException(
                "Platform [{$platformKey}] ({$platformConfig['label']}) is not yet available. "
                ."Its driver is still rolling out."
            );
        }

        return new $driverClass($credentials, $platformConfig, $this->config);
    }

    public function credentials(): Credentials
    {
        $resolver = $this->credentialResolver();
        $credentials = $resolver->resolve($this->tenant, $this->platform);

        if (! $credentials) {
            event(new CredentialsMissing($this->tenant, $this->platform));
            throw new CredentialsMissingException(
                'No platform credentials configured'.($this->tenant ? ' for the given tenant.' : '.')
            );
        }

        if ($this->sandboxOverride !== null) {
            $credentials->sandbox = $this->sandboxOverride;
        }

        return $credentials;
    }

    protected function credentialResolver(): CredentialResolver
    {
        if ($this->credentialCallback) {
            return new CallbackCredentialResolver($this->credentialCallback);
        }

        return match ($this->config['credentials']['driver'] ?? 'env') {
            'database' => new DatabaseCredentialResolver($this->config),
            'callback' => throw new OmniConnectException('Register the callback via OmniConnect::resolveCredentialsUsing().'),
            default    => new EnvCredentialResolver($this->config),
        };
    }

    public function transport(): Transport
    {
        if ($this->container->bound(Transport::class)) {
            return $this->container->make(Transport::class);
        }

        return ($this->config['transport'] ?? 'http') === 'mock'
            ? new MockTransport()
            : new HttpTransport((int) ($this->config['timeout'] ?? 30));
    }

    public function tokenManager(): TokenManager
    {
        return new TokenManager($this->transport(), $this->config['token'] ?? []);
    }

    public function config(): array
    {
        return $this->config;
    }

    public function container(): Container
    {
        return $this->container;
    }
}
