<?php

namespace Nosh\OmniConnect\Contracts;

use Nosh\OmniConnect\Data\Order;

/**
 * A platform driver isolates everything specific to one delivery platform:
 * its host, its login shape, its endpoint paths, the exact request/response
 * JSON, and how it signs the webhooks it sends us.
 *
 * DeliveryHeroDriver (foodpanda) is the first implementation. A new platform
 * = a new driver; no domain/client code changes.
 *
 * The driver is intentionally a pure mapper of URLs + payloads. The domain
 * Clients (OrderClient, CatalogClient, …) do the authenticated HTTP, retry,
 * event and idempotency work using these descriptors.
 */
interface DeliveryPlatformDriver
{
    /** Machine key, e.g. 'delivery_hero'. */
    public function key(): string;

    /** Human label, e.g. 'Delivery Hero (foodpanda)'. */
    public function label(): string;

    /** Resolved base host for the active sandbox/production mode. */
    public function host(): string;

    /** Build a full URL from a path fragment against the resolved host. */
    public function url(string $path): string;

    // ---- Authentication (Login API → short-lived JWT) --------------------

    public function loginUrl(): string;

    /** Form-encoded login params (grant_type=client_credentials, username, password). */
    public function loginParams(): array;

    /** @return array{token:string, expires_in:int} */
    public function parseLoginResponse(array $body): array;

    /** Headers that carry the bearer token on an authorized call. */
    public function authHeaders(string $token): array;

    // ---- Orders: outbound (Integration Middleware API) -------------------

    /** URL for POST order status update, keyed by the platform's order token. */
    public function orderStatusUrl(string $orderToken): string;

    /** Request body to ACCEPT an order. */
    public function acceptBody(array $opts = []): array;

    /** Request body to REJECT an order with a reason. */
    public function rejectBody(string $reason, array $opts = []): array;

    /** Body for an order_picked_up status update (same status endpoint). */
    public function pickedUpBody(): array;

    /** URL + body to mark an order prepared/ready for pickup. */
    public function preparedUrl(string $orderToken): string;

    public function preparedBody(array $opts = []): array;

    /** URL to adjust an order's preparation/pickup time. */
    public function adjustPreparationTimeUrl(string $orderToken): string;

    /** URL to add/remove products on a live order. */
    public function modifyProductsUrl(string $orderToken): string;

    // ---- Orders: inbound (Plugin API) ------------------------------------

    /** Normalize a raw incoming order-dispatch payload into a canonical Order. */
    public function parseIncomingOrder(array $payload): Order;

    /** Normalize a raw incoming status/cancellation payload into a canonical Order (partial). */
    public function parseIncomingStatus(array $payload): Order;

    // ---- Shared ----------------------------------------------------------

    /** Extract a human message from a platform error body ({code,message}). */
    public function parseError(array $body): string;

    /**
     * Verify an INBOUND Plugin-API call actually came from the platform.
     * For Delivery Hero this validates the JWT in the Authorization header
     * against the shared secret and checks the `service: middleware` claim.
     */
    public function verifyInboundAuth(array $headers, ?string $secret): bool;
}
