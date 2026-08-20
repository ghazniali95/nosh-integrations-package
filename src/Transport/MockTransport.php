<?php

namespace Nosh\OmniConnect\Transport;

use Nosh\OmniConnect\Contracts\Transport;

/**
 * In-package fake Delivery Hero Integration Middleware.
 *
 * Lets the entire flow — login, accept/reject, mark-prepared, catalog push,
 * store toggle — run and be tested with NO real credentials and NO network.
 * It returns responses in Delivery Hero's documented shapes:
 *   - /v2/login              → { access_token, expires_in: 1800, token_type }
 *   - authorized calls w/o a bearer token → 401 { code:"POS_ERROR", message }
 *
 * Flip config transport 'mock' → 'http' when real credentials arrive; the rest
 * of the package is identical.
 */
class MockTransport implements Transport
{
    public function post(string $url, array $payload, array $headers = []): array
    {
        return $this->route('POST', $url, $payload, $headers);
    }

    public function put(string $url, array $payload, array $headers = []): array
    {
        return $this->route('PUT', $url, $payload, $headers);
    }

    public function get(string $url, array $query = [], array $headers = []): array
    {
        return $this->route('GET', $url, $query, $headers);
    }

    public function postForm(string $url, array $params, array $headers = []): array
    {
        // Login is the only form-encoded endpoint.
        if (str_contains($url, '/v2/login')) {
            return $this->login($params);
        }

        return $this->route('POST', $url, $params, $headers);
    }

    public function sendRaw(string $method, string $url, string $rawBody, array $headers = []): array
    {
        return $this->route(strtoupper($method), $url, ['_raw' => $rawBody], $headers);
    }

    // ---- Endpoint router --------------------------------------------------

    protected function route(string $method, string $url, array $body, array $headers): array
    {
        // Every non-login call must carry a bearer token.
        if (! $this->hasBearer($headers)) {
            return $this->error(401, 'Missing or invalid access token.');
        }

        // POST /v2/order/status/{orderToken} — accept / reject / picked-up
        if (preg_match('#/v2/order/status/#', $url)) {
            $status = $body['status'] ?? null;

            if ($status === 'order_rejected' && empty($body['reason'])) {
                return ['status' => 400, 'body' => ['code' => 'INVALID_REQUEST', 'message' => 'A rejection reason is required.']];
            }
            if ($status === 'order_accepted' && empty($body['acceptanceTime'])) {
                return ['status' => 400, 'body' => ['code' => 'INVALID_REQUEST', 'message' => 'acceptanceTime is required.']];
            }

            return $this->ok(['message' => 'Order status successfully changed.']);
        }

        // POST /v2/orders/{orderToken}/preparation-completed → { code: OK }
        if (preg_match('#/v2/orders/.+/preparation-completed$#', $url)) {
            return $this->ok(['code' => 'OK']);
        }

        // POST /v2/orders/{orderToken}/adjust-preparation-time → 204
        if (preg_match('#/v2/orders/.+/adjust-preparation-time$#', $url)) {
            if (empty($body['expectedPickupAt'])) {
                return ['status' => 400, 'body' => ['code' => 'PREPARATION_TIME_BELOW_ALLOWED_MIN_TIME', 'message' => 'expectedPickupAt required.']];
            }

            return ['status' => 204, 'body' => []];
        }

        // POST /v2/order/{orderToken}/modifications/product → 202
        if (preg_match('#/v2/order/.+/modifications/product$#', $url)) {
            return ['status' => 202, 'body' => ['message' => 'Order modification request has been accepted.']];
        }

        // --- Store: vendor availability -----------------------------------
        if (preg_match('#/remoteVendors/.+/availability$#', $url)) {
            if ($method === 'GET') {
                return $this->ok([[
                    'availabilityState' => 'OPEN',
                    'availabilityStates' => ['CLOSED_UNTIL', 'CLOSED', 'OPEN'],
                    'changeable' => true,
                    'closingMinutes' => [120, 60, 30],
                    'closingReasons' => ['TOO_BUSY_KITCHEN', 'CLOSED', 'OTHER'],
                    'platformKey' => 'FP_PK',
                    'platformRestaurantId' => '123456789',
                    'platformType' => 'FOODPANDA',
                ]]);
            }

            return ['status' => 200, 'body' => []]; // PUT open/close
        }

        // --- Store: POS reachability (deprecated) --------------------------
        if (preg_match('#/posReachabilityStatus$#', $url)) {
            return ['status' => 202, 'body' => $body];
        }

        // --- Catalog: submit (chain + centralized kitchen) → 202 ----------
        if (preg_match('#/catalog$#', $url) && $method === 'PUT') {
            return ['status' => 202, 'body' => ['catalogImportId' => 'imp-'.substr(md5($url.serialize($body)), 0, 10), 'status' => 'in_progress']];
        }

        // --- Catalog: item availability → 204 -----------------------------
        if (preg_match('#/catalog/items/availability$#', $url)) {
            if (empty($body['items'])) {
                return ['status' => 400, 'body' => ['code' => 'POS_ERROR', 'message' => 'items required.']];
            }

            return ['status' => 204, 'body' => []];
        }

        // --- Catalog: unavailable items -----------------------------------
        if (preg_match('#/catalog/items/unavailable$#', $url)) {
            return $this->ok([]);
        }

        // --- Catalog: menu-import logs ------------------------------------
        if (preg_match('#/menu-import-logs$#', $url)) {
            return $this->ok(['menuImportLogs' => []]);
        }

        // --- Catalog: menu import (deprecated XML) ------------------------
        if (preg_match('#/menuImport$#', $url) || preg_match('#/v2/menu/[^/]+/[^/]+$#', $url)) {
            return $this->ok(['status' => 'SUBMITTED']);
        }

        // --- Catalog: platform vendors ------------------------------------
        if (preg_match('#/platform-vendors$#', $url)) {
            return $this->ok(['platformVendors' => []]);
        }

        // --- Catalog: modify product --------------------------------------
        if (preg_match('#/modify-product$#', $url)) {
            return ['status' => 200, 'body' => ['message' => 'Product modification accepted.']];
        }

        // --- Reports: order ids / detail ----------------------------------
        if (preg_match('#/orders/ids$#', $url)) {
            return $this->ok(['orderIdentifiers' => []]);
        }
        if (preg_match('#/chains/[^/]+/orders/[^/]+$#', $url) && $method === 'GET') {
            return $this->ok(['order' => ['orderId' => 'ORD-1', 'status' => 'accepted']]);
        }

        return $this->ok(['status' => 'ok']);
    }

    protected function login(array $params): array
    {
        $grant = $params['grant_type'] ?? null;
        $user  = $params['username'] ?? null;
        $pass  = $params['password'] ?? null;

        if ($grant !== 'client_credentials' || empty($user) || empty($pass)) {
            return $this->error(401, 'Invalid client credentials.');
        }

        return [
            'status' => 200,
            'body'   => [
                'access_token' => $this->fakeJwt((string) $user),
                'expires_in'   => 1800,
                'token_type'   => 'bearer',
            ],
        ];
    }

    // ---- Helpers ----------------------------------------------------------

    protected function hasBearer(array $headers): bool
    {
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === 'authorization') {
                return (bool) preg_match('/^Bearer\s+\S+/i', trim((string) $v));
            }
        }

        return false;
    }

    protected function ok(array $body): array
    {
        return ['status' => 200, 'body' => $body];
    }

    protected function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['code' => 'POS_ERROR', 'message' => $message]];
    }

    /** A structurally-valid (unsigned, mock) JWT string. */
    protected function fakeJwt(string $subject): string
    {
        $seg = fn (array $d) => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');

        return $seg(['alg' => 'HS256', 'typ' => 'JWT'])
            .'.'.$seg(['sub' => $subject, 'iat' => time(), 'exp' => time() + 1800, 'mock' => true])
            .'.'.rtrim(strtr(base64_encode('mock-signature'), '+/', '-_'), '=');
    }
}
