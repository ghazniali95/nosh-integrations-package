<?php

namespace Nosh\OmniConnect\Drivers;

use Nosh\OmniConnect\Data\Customer;
use Nosh\OmniConnect\Data\Order;
use Nosh\OmniConnect\Data\OrderItem;

/**
 * Delivery Hero (foodpanda) Integration Middleware driver.
 *
 * AUTH is grounded in the Middleware OpenAPI spec (v2):
 *   POST /v2/login  (x-www-form-urlencoded: grant_type=client_credentials,
 *                    username, password)  →  { access_token, expires_in:1800,
 *                    token_type:"bearer" }; Bearer token on every other call.
 *
 * The order-status endpoint path is grounded too:
 *   POST /v2/order/status/{orderToken}
 *
 * ⚠️ The exact accept/reject request BODIES and the inbound order-dispatch
 * payload FIELD NAMES below are being finalized against the Order Management
 * spec page. They are isolated here so wiring/tests are unaffected when the
 * precise shapes land — only this file changes.
 */
class DeliveryHeroDriver extends AbstractDriver
{
    public function key(): string
    {
        return 'delivery_hero';
    }

    // ---- Authentication ---------------------------------------------------

    public function loginUrl(): string
    {
        return $this->url('/v2/login');
    }

    public function loginParams(): array
    {
        return [
            'grant_type' => 'client_credentials',
            'username'   => $this->credentials->username,
            'password'   => $this->credentials->password,
        ];
    }

    public function parseLoginResponse(array $body): array
    {
        return [
            'token'      => trim((string) ($body['access_token'] ?? '')),
            'expires_in' => (int) ($body['expires_in'] ?? 1800),
        ];
    }

    // ---- Orders: outbound (grounded in Middleware spec v1.0.0) ------------

    /** POST /v2/order/status/{orderToken} — accept, reject, and picked-up. */
    public function orderStatusUrl(string $orderToken): string
    {
        return $this->url('/v2/order/status/'.rawurlencode($orderToken));
    }

    /**
     * order_accepted. `acceptanceTime` (RFC-3339) is required by the spec; we
     * default it to "now" when the caller doesn't supply one. `remoteOrderId`
     * (POS-side id) and `modifications` are optional.
     */
    public function acceptBody(array $opts = []): array
    {
        return array_filter([
            'status'         => 'order_accepted',
            'acceptanceTime' => $opts['acceptance_time'] ?? gmdate(DATE_RFC3339),
            'remoteOrderId'  => $opts['remote_order_id'] ?? null,
            'modifications'  => $opts['modifications'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * order_rejected. `reason` must be one of the spec's enum values;
     * `message` (free text) and `unavailableItems` are optional.
     */
    public function rejectBody(string $reason, array $opts = []): array
    {
        return array_filter([
            'status'           => 'order_rejected',
            'reason'           => $reason,
            'message'          => $opts['message'] ?? null,
            'unavailableItems' => $opts['unavailable_items'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /** order_picked_up — same status endpoint (vendor-delivery/pickup only). */
    public function pickedUpBody(): array
    {
        return ['status' => 'order_picked_up'];
    }

    /** POST /v2/orders/{orderToken}/preparation-completed — no request body. */
    public function preparedUrl(string $orderToken): string
    {
        return $this->url('/v2/orders/'.rawurlencode($orderToken).'/preparation-completed');
    }

    public function preparedBody(array $opts = []): array
    {
        return []; // spec: empty body
    }

    /** POST /v2/orders/{orderToken}/adjust-preparation-time. */
    public function adjustPreparationTimeUrl(string $orderToken): string
    {
        return $this->url('/v2/orders/'.rawurlencode($orderToken).'/adjust-preparation-time');
    }

    /** POST /v2/order/{orderToken}/modifications/product (note: singular "order"). */
    public function modifyProductsUrl(string $orderToken): string
    {
        return $this->url('/v2/order/'.rawurlencode($orderToken).'/modifications/product');
    }

    // ---- Orders: inbound (Plugin API) ------------------------------------

    /**
     * Map a Dispatch-Order payload → canonical Order, per the POS Plugin API
     * schema. Unknown/extra properties are ignored by design (the spec warns the
     * payload grows over time). The raw payload is always retained.
     */
    public function parseIncomingOrder(array $payload): Order
    {
        $order = $payload['order'] ?? $payload; // some deliveries nest under `order`
        $token = (string) ($order['token'] ?? $order['code'] ?? '');

        $items = [];
        foreach (($order['products'] ?? $order['items'] ?? []) as $p) {
            $qty = (int) ($p['quantity'] ?? 1);
            $unit = $p['paidPrice'] ?? $p['unitPrice'] ?? $p['price'] ?? 0;
            $items[] = new OrderItem(
                remoteId: (string) ($p['remoteCode'] ?? $p['id'] ?? $p['sku'] ?? ''),
                name: (string) ($p['name'] ?? ''),
                quantity: $qty,
                unitPrice: $this->paisa($unit),
                total: $this->paisa($p['totalPrice'] ?? $p['total'] ?? ((float) $unit * $qty)),
                modifiers: $this->mapModifiers($p['selectedToppings'] ?? $p['toppings'] ?? $p['modifiers'] ?? []),
                // DH sends the size as an object ({name}); older payloads a string.
                variation: $this->variationName($p['variation'] ?? null) ?? $p['categoryName'] ?? null,
                comment: $p['comment'] ?? null,
                platformId: isset($p['id']) ? (string) $p['id'] : null,
                remoteCode: $this->blankToNull($p['remoteCode'] ?? null),
                discount: $this->paisa($p['discountAmount'] ?? 0),
            );
        }

        $price = $order['price'] ?? [];
        $payment = $order['payment'] ?? [];
        $delivery = (array) ($order['delivery'] ?? []);
        [$discounts, $discountTotal] = $this->parseDiscounts($order, $price);

        $customer = isset($order['customer']) ? Customer::fromArray((array) $order['customer']) : null;
        $address = (array) ($delivery['address'] ?? []);
        if ($address !== []) {
            $customer ??= new Customer();
            $customer->address ??= $this->addressLine($address);
            $customer->lat ??= isset($address['latitude']) ? (float) $address['latitude'] : null;
            $customer->lng ??= isset($address['longitude']) ? (float) $address['longitude'] : null;
        }

        return new Order(
            orderToken: $token,
            orderCode: $order['code'] ?? $order['shortCode'] ?? null,
            platform: 'delivery_hero',
            vendorId: (string) ($order['platformRestaurant']['id'] ?? $order['vendorId'] ?? '') ?: null,
            status: 'received',
            expeditionType: $order['expeditionType'] ?? null,
            customer: $customer,
            items: $items,
            // `subTotal` is the products' sum; `totalNet` (ex-VAT total) is the
            // older fallback this field was first read from.
            subtotal: $this->paisa($price['subTotal'] ?? $price['totalNet'] ?? 0),
            deliveryFee: $this->sumFees($price['deliveryFees'] ?? []),
            total: $this->paisa($price['grandTotal'] ?? $order['total'] ?? 0),
            currency: $order['localInfo']['currencySymbol'] ?? $order['currency'] ?? 'PKR',
            comment: $order['comments']['customerComment'] ?? $order['comment'] ?? null,
            paidOnline: in_array(strtolower((string) ($payment['status'] ?? $payment['type'] ?? '')), ['paid', 'prepaid', 'online'], true),
            placedAt: $order['createdAt'] ?? $order['placedAt'] ?? null,
            test: (bool) ($order['test'] ?? false),
            callbackUrls: (array) ($order['callbackUrls'] ?? []),
            eventId: $token,
            raw: $payload,
            discountTotal: $discountTotal,
            discounts: $discounts,
            containerCharge: $this->paisa($price['containerCharge'] ?? 0),
            riderTip: $this->paisa($price['riderTip'] ?? 0),
            collectFromCustomer: $this->paisa($price['collectFromCustomer'] ?? 0),
            payRestaurant: isset($price['payRestaurant']) ? $this->paisa($price['payRestaurant']) : null,
            paymentType: isset($payment['type']) ? (string) $payment['type'] : null,
            preOrder: (bool) ($order['preOrder'] ?? false),
            expectedDeliveryTime: $delivery['expectedDeliveryTime'] ?? null,
            riderPickupTime: $delivery['riderPickupTime'] ?? null,
            pickupTime: $order['pickup']['pickupTime'] ?? null,
        );
    }

    /**
     * Order-level discounts. DH lists them under `discounts[]` (each with an
     * `amount` and, where it knows, who sponsors it) and/or a single
     * `price.discountAmountTotal`. The list wins when present; the total is
     * the fallback so a payload with only the total still nets correctly.
     *
     * @return array{0:array<int,array{name:?string,amount:int,type:?string,sponsor:?string}>,1:int}
     */
    protected function parseDiscounts(array $order, array $price): array
    {
        $discounts = [];
        foreach ((array) ($order['discounts'] ?? []) as $d) {
            $discounts[] = [
                'name'    => $d['name'] ?? null,
                'amount'  => $this->paisa($d['amount'] ?? $d['value'] ?? 0),
                'type'    => $d['type'] ?? null,
                'sponsor' => $this->sponsorOf($d),
            ];
        }

        $sum = array_sum(array_column($discounts, 'amount'));
        $total = $sum ?: $this->paisa($price['discountAmountTotal'] ?? 0);

        return [$discounts, $total];
    }

    /** Who funds a discount, normalised to lower case; null when the payload does not say. */
    protected function sponsorOf(array $d): ?string
    {
        $raw = $d['sponsor'] ?? $d['sponsorship'] ?? $d['fundedBy'] ?? null;
        if (is_array($raw)) {
            $raw = $raw['type'] ?? $raw['name'] ?? null;
        }

        return $raw === null || $raw === '' ? null : strtolower((string) $raw);
    }

    /** One readable delivery-address line from DH's structured address. */
    protected function addressLine(array $a): ?string
    {
        if ($a === []) {
            return null;
        }

        $parts = array_filter([
            $a['flatNumber'] ?? null,
            $a['floor'] ?? null,
            $a['building'] ?? null,
            trim(($a['number'] ?? '').' '.($a['street'] ?? '')) ?: null,
            $a['deliveryArea'] ?? null,
            $a['city'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $line = implode(', ', $parts);
        if (! empty($a['deliveryInstructions'])) {
            $line .= ($line !== '' ? ' — ' : '').$a['deliveryInstructions'];
        }

        return $line !== '' ? $line : null;
    }

    protected function variationName(mixed $v): ?string
    {
        if (is_array($v)) {
            $v = $v['name'] ?? null;
        }

        return $this->blankToNull($v);
    }

    protected function blankToNull(mixed $v): ?string
    {
        return $v === null || $v === '' ? null : (string) $v;
    }

    /** Sum a price.deliveryFees[] array (each fee may carry an `amount`/`value`). */
    protected function sumFees(array $fees): int
    {
        $total = 0.0;
        foreach ($fees as $f) {
            $total += (float) ($f['amount'] ?? $f['value'] ?? 0);
        }

        return $this->paisa($total);
    }

    /**
     * Map an inbound OrderStatusUpdated body. The order is identified by the
     * path's remoteOrderId (set by the caller), so this only maps the status.
     * DH enum → canonical: ORDER_CANCELLED→cancelled, ORDER_PICKED_UP→picked_up.
     */
    public function parseIncomingStatus(array $payload): Order
    {
        $map = [
            'ORDER_CANCELLED'  => 'cancelled',
            'ORDER_PICKED_UP'  => 'picked_up',
        ];
        $raw = (string) ($payload['status'] ?? '');

        return new Order(
            orderToken: (string) ($payload['token'] ?? ''),
            status: $map[$raw] ?? strtolower($raw ?: 'updated'),
            comment: $payload['message'] ?? null,
            raw: $payload,
        );
    }

    // ---- Inbound auth (Middleware JWT) -----------------------------------

    /**
     * Every inbound Plugin-API request carries a JWT in the Authorization
     * header, signed (HS512) with the shared secret issued alongside the
     * credentials, and containing the claim `service: middleware`. We verify
     * both. (Secret differs between staging and production.)
     */
    public function verifyInboundAuth(array $headers, ?string $secret): bool
    {
        if (empty($secret)) {
            // Nothing to verify against means we cannot know who is calling.
            // Deciding to skip verification (mock / local dev) is the
            // middleware's call, made from config — never a silent default here.
            return false;
        }

        $bearer = '';
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === 'authorization') {
                $bearer = is_array($v) ? ($v[0] ?? '') : (string) $v;
                break;
            }
        }
        $token = trim(preg_replace('/^Bearer\s+/i', '', trim($bearer)));
        if ($token === '') {
            return false;
        }

        $payload = \Nosh\OmniConnect\Support\Jwt::verify($token, $secret);

        return is_array($payload) && ($payload['service'] ?? null) === 'middleware';
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * Map `selectedToppings` into nested OrderItems, recursing into `children`
     * (toppings-of-toppings). Prices are DH `price` strings → paisa.
     *
     * @param array<int,array> $mods
     */
    protected function mapModifiers(array $mods): array
    {
        $out = [];
        foreach ($mods as $m) {
            $qty = (int) ($m['quantity'] ?? 1);
            $out[] = new OrderItem(
                remoteId: (string) ($m['remoteCode'] ?? $m['id'] ?? ''),
                name: (string) ($m['name'] ?? ''),
                quantity: $qty,
                unitPrice: $this->paisa($m['price'] ?? $m['unitPrice'] ?? 0),
                total: $this->paisa($m['totalPrice'] ?? $m['total'] ?? ((float) ($m['price'] ?? 0) * $qty)),
                modifiers: $this->mapModifiers($m['children'] ?? []),
                variation: $m['type'] ?? null,
                platformId: isset($m['id']) ? (string) $m['id'] : null,
                remoteCode: $this->blankToNull($m['remoteCode'] ?? null),
            );
        }

        return $out;
    }

    /** Convert a major-unit money value (e.g. 18.00) to integer paisa. */
    protected function paisa(int|float|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
