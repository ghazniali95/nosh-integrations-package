<?php

namespace Nosh\OmniConnect\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nosh\OmniConnect\OmniConnectManager;

/**
 * Verifies that an inbound Plugin-API request genuinely came from Delivery
 * Hero: the Authorization JWT must validate against the shared webhook secret
 * and carry the `service: middleware` claim (delegated to the driver).
 *
 * Skipped only when webhook.verify_signature is false, or when no secret is
 * configured AND the transport is `mock` (local dev). Anywhere else a missing
 * secret is a 401: a live install with a blank OMNICONNECT_WEBHOOK_SECRET used
 * to accept anyone's orders, which is the wrong way to fail.
 */
class VerifyMiddlewareJwt
{
    public function __construct(protected OmniConnectManager $manager)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $fullConfig = $this->manager->config();
        $config = $fullConfig['webhook'] ?? [];

        if (! ($config['verify_signature'] ?? true)) {
            return $next($request);
        }

        // Resolve THIS restaurant's webhook secret from the {remoteId} in the
        // path, so every tenant is verified against its own shared secret.
        $remoteId = (string) $request->route('remoteId');
        $credentials = $remoteId !== '' ? $this->manager->credentialsForRemoteId($remoteId) : null;
        $secret = $credentials?->webhookSecret ?: ($config['secret'] ?? null);

        if (empty($secret)) {
            // Single-install on the mock transport with no secret → local dev (skip).
            // Everything else fails closed: we cannot identify/verify the caller.
            $isMultiTenant = ($fullConfig['credentials']['driver'] ?? 'env') === 'database' || $this->manager->hasInboundResolver();
            $isMock = ($fullConfig['transport'] ?? 'http') === 'mock';
            if (! $isMultiTenant && $isMock && empty($config['secret'])) {
                return $next($request);
            }

            return response()->json(['code' => 'UNAUTHORIZED', 'message' => 'Unknown vendor or missing webhook secret.'], 401);
        }

        // verifyInboundAuth is pure JWT logic, but building the driver needs a
        // Credentials — use the resolved tenant's, or a bare one for the platform.
        $driver = $this->manager->driver($credentials ?? new \Nosh\OmniConnect\Data\Credentials(
            platform: $fullConfig['default'] ?? 'delivery_hero'
        ));
        $ok = $driver->verifyInboundAuth($this->headerMap($request), $secret);

        if (! $ok) {
            return response()->json(['code' => 'UNAUTHORIZED', 'message' => 'Invalid middleware token.'], 401);
        }

        return $next($request);
    }

    /** @return array<string,string> */
    protected function headerMap(Request $request): array
    {
        $out = [];
        foreach ($request->headers->all() as $key => $values) {
            $out[$key] = is_array($values) ? ($values[0] ?? '') : (string) $values;
        }

        return $out;
    }
}
