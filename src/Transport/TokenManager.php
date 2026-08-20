<?php

namespace Nosh\OmniConnect\Transport;

use Illuminate\Support\Facades\Cache;
use Nosh\OmniConnect\Contracts\DeliveryPlatformDriver;
use Nosh\OmniConnect\Contracts\Transport;
use Nosh\OmniConnect\Data\Credentials;
use Nosh\OmniConnect\Exceptions\AuthenticationException;

/**
 * Obtains and caches the platform's short-lived access token.
 *
 * Delivery Hero's /v2/login returns a JWT valid for ~30 min (expires_in:1800).
 * We log in once, cache the token until shortly before it expires, and reuse it
 * across calls. On a 401 the caller invokes forget() to force a fresh login and
 * retries once.
 */
class TokenManager
{
    /**
     * @param object|null $cache Optional cache repository (get/put/forget).
     *                           Defaults to Laravel's cache store. Injectable so
     *                           the package can run outside a full Laravel app.
     */
    public function __construct(
        protected Transport $transport,
        protected array $config = [],
        protected ?object $cache = null,
    ) {
    }

    public function token(Credentials $credentials, DeliveryPlatformDriver $driver): string
    {
        $key = $credentials->tokenCacheKey();

        if ($cached = $this->cache()->get($key)) {
            return $cached;
        }

        return $this->login($credentials, $driver);
    }

    public function forget(Credentials $credentials): void
    {
        $this->cache()->forget($credentials->tokenCacheKey());
    }

    protected function login(Credentials $credentials, DeliveryPlatformDriver $driver): string
    {
        if (! $credentials->hasLogin()) {
            throw new AuthenticationException('No username/password configured for this vendor.');
        }

        $response = $this->transport->postForm($driver->loginUrl(), $driver->loginParams());

        if (($response['status'] ?? 0) !== 200) {
            throw new AuthenticationException(
                'Login failed: '.$driver->parseError($response['body'] ?? [])
            );
        }

        $parsed = $driver->parseLoginResponse($response['body'] ?? []);
        $token  = $parsed['token'] ?? null;

        if (empty($token)) {
            throw new AuthenticationException('Login response did not contain an access token.');
        }

        $ttl = max(30, (int) ($parsed['expires_in'] ?? 1800) - (int) ($this->config['ttl_buffer'] ?? 120));
        $this->cache()->put($credentials->tokenCacheKey(), $token, $ttl);

        return $token;
    }

    protected function cache()
    {
        if ($this->cache) {
            return $this->cache;
        }

        $store = $this->config['cache_store'] ?? null;

        return $store ? Cache::store($store) : Cache::store();
    }
}
