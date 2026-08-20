<?php

/**
 * Standalone verification harness — NO Laravel, NO credentials, NO network.
 *
 * Proves the platform-neutral core against the Delivery Hero Middleware spec:
 * host resolution, login shape, order-status endpoints + bodies, reject-reason
 * validation, the incoming-order mapper, and the MockTransport responses.
 *
 * Run:  php tests/verify.php
 */

spl_autoload_register(function ($class) {
    $prefix = 'Nosh\\OmniConnect\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__.'/../src/'.$rel.'.php';
    if (is_file($file)) {
        require $file;
    }
});

use Nosh\OmniConnect\Catalog\CatalogClient;
use Nosh\OmniConnect\Data\Credentials;
use Nosh\OmniConnect\Drivers\DeliveryHeroDriver;
use Nosh\OmniConnect\Http\AuthorizedClient;
use Nosh\OmniConnect\Orders\OrderClient;
use Nosh\OmniConnect\Reports\ReportClient;
use Nosh\OmniConnect\Store\StoreClient;
use Nosh\OmniConnect\Support\Jwt;
use Nosh\OmniConnect\Support\RejectReason;
use Nosh\OmniConnect\Transport\MockTransport;
use Nosh\OmniConnect\Transport\TokenManager;

/** Tiny in-memory cache so TokenManager runs without Laravel. */
class ArrayCache
{
    private array $s = [];

    public function get($k)
    {
        $e = $this->s[$k] ?? null;
        if (! $e) {
            return null;
        }
        if ($e[1] !== null && $e[1] < time()) {
            unset($this->s[$k]);

            return null;
        }

        return $e[0];
    }

    public function put($k, $v, $ttl = null)
    {
        $this->s[$k] = [$v, $ttl ? time() + $ttl : null];
    }

    public function forget($k)
    {
        unset($this->s[$k]);
    }
}

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? "  ✓ " : "  ✗ ").$label.PHP_EOL;
    $ok ? $pass++ : $fail++;
}
function throws(string $label, callable $fn): void
{
    $threw = false;
    try {
        $fn();
    } catch (\Throwable) {
        $threw = true;
    }
    check($label, $threw);
}

$platformConfig = [
    'label' => 'Delivery Hero (foodpanda)',
    'hosts' => [
        'sandbox'    => 'https://integration-middleware.stg.restaurant-partners.com',
        'production' => 'https://integration-middleware.restaurant-partners.com',
    ],
];

$sandboxCreds = new Credentials(platform: 'delivery_hero', username: 'u', password: 'p', sandbox: true, chainCode: 'CHAIN1');
$prodCreds    = new Credentials(platform: 'delivery_hero', username: 'u', password: 'p', sandbox: false);

$driver     = new DeliveryHeroDriver($sandboxCreds, $platformConfig);
$prodDriver = new DeliveryHeroDriver($prodCreds, $platformConfig);

echo "── Driver: hosts & auth ──".PHP_EOL;
check('sandbox host resolves to stg', $driver->host() === 'https://integration-middleware.stg.restaurant-partners.com');
check('production host drops .stg', $prodDriver->host() === 'https://integration-middleware.restaurant-partners.com');
check('login url is /v2/login', $driver->loginUrl() === $driver->host().'/v2/login');
$lp = $driver->loginParams();
check('login params use client_credentials grant', $lp['grant_type'] === 'client_credentials' && $lp['username'] === 'u' && $lp['password'] === 'p');
$parsed = $driver->parseLoginResponse(['access_token' => "tok\n", 'expires_in' => 1800]);
check('parseLoginResponse trims token + reads ttl', $parsed['token'] === 'tok' && $parsed['expires_in'] === 1800);
check('authHeaders emits Bearer', $driver->authHeaders('abc') === ['Authorization' => 'Bearer abc']);

echo "── Driver: order endpoints & bodies ──".PHP_EOL;
check('status url', $driver->orderStatusUrl('TKN1') === $driver->host().'/v2/order/status/TKN1');
check('prepared url is plural + preparation-completed', $driver->preparedUrl('TKN1') === $driver->host().'/v2/orders/TKN1/preparation-completed');
check('prepared body is empty', $driver->preparedBody() === []);
$accept = $driver->acceptBody();
check('accept: status order_accepted', ($accept['status'] ?? null) === 'order_accepted');
check('accept: defaults acceptanceTime (RFC-3339)', ! empty($accept['acceptanceTime']) && strtotime($accept['acceptanceTime']) !== false);
$accept2 = $driver->acceptBody(['remote_order_id' => 'POS-9', 'acceptance_time' => '2026-01-01T00:00:00+05:00']);
check('accept: passes remoteOrderId + acceptanceTime', $accept2['remoteOrderId'] === 'POS-9' && $accept2['acceptanceTime'] === '2026-01-01T00:00:00+05:00');
$reject = $driver->rejectBody(RejectReason::ITEM_UNAVAILABLE, ['message' => 'no tomatoes']);
check('reject: status + reason + message', $reject['status'] === 'order_rejected' && $reject['reason'] === 'ITEM_UNAVAILABLE' && $reject['message'] === 'no tomatoes');
check('pickedUp body', $driver->pickedUpBody() === ['status' => 'order_picked_up']);
check('parseError reads message', $driver->parseError(['code' => 'POS_ERROR', 'message' => 'boom']) === 'boom');

echo "── Reject reasons ──".PHP_EOL;
check('24 canonical reasons', count(RejectReason::all()) === 24);
check('ITEM_UNAVAILABLE valid', RejectReason::isValid('ITEM_UNAVAILABLE'));
check('NONSENSE invalid', ! RejectReason::isValid('NONSENSE'));

echo "── Incoming order mapper ──".PHP_EOL;
$payload = [
    'token'              => 'ORD-TOKEN-123',
    'code'               => 'b2c4',
    'expeditionType'     => 'delivery',
    'customer'           => ['firstName' => 'Ali', 'lastName' => 'Khan', 'mobilePhone' => '0300'],
    'comments'           => ['customerComment' => 'no onions'],
    'platformRestaurant' => ['id' => 'sq-abcd'],
    'test'               => true,
    'products'           => [
        ['id' => 'P1', 'remoteCode' => 'SKU1', 'name' => 'Karahi', 'quantity' => 2, 'paidPrice' => '18.00', 'totalPrice' => '36.00',
            'selectedToppings' => [['id' => 'T1', 'name' => 'Extra Naan', 'quantity' => 1, 'price' => '0.80']]],
    ],
    'price'        => ['grandTotal' => '36.80', 'totalNet' => '34.30'],
    'payment'      => ['status' => 'paid', 'type' => 'paid'],
    'callbackUrls' => ['orderAcceptedUrl' => 'https://dh/accept', 'orderRejectedUrl' => 'https://dh/reject'],
];
$order = $driver->parseIncomingOrder($payload);
check('maps order token', $order->orderToken === 'ORD-TOKEN-123');
check('status = received', $order->status === 'received');
check('expedition type', $order->expeditionType === 'delivery');
check('customer firstName+lastName→name, mobilePhone→phone', $order->customer?->name === 'Ali Khan' && $order->customer?->phone === '0300');
check('vendor from platformRestaurant.id', $order->vendorId === 'sq-abcd');
check('comment from comments.customerComment', $order->comment === 'no onions');
check('test flag captured', $order->test === true);
check('1 line item', count($order->items) === 1);
check('item remoteId + qty', $order->items[0]->remoteId === 'SKU1' && $order->items[0]->quantity === 2);
check('money→paisa (18.00→1800)', $order->items[0]->unitPrice === 1800);
check('topping/modifier nested', count($order->items[0]->modifiers) === 1 && $order->items[0]->modifiers[0]->unitPrice === 80);
check('subtotal from totalNet', $order->subtotal === 3430);
check('grand total→paisa', $order->total === 3680);
check('paid online from payment.status', $order->paidOnline === true);
check('callbackUrls captured', ($order->callbackUrls['orderAcceptedUrl'] ?? null) === 'https://dh/accept');
check('event id = token (idempotency)', $order->eventId === 'ORD-TOKEN-123');
check('raw payload retained', $order->raw === $payload);

echo "── MockTransport (fake Delivery Hero) ──".PHP_EOL;
$mock = new MockTransport();
$login = $mock->postForm($driver->loginUrl(), $driver->loginParams());
check('login 200 + access_token + expires_in 1800', $login['status'] === 200 && ! empty($login['body']['access_token']) && $login['body']['expires_in'] === 1800);
$badLogin = $mock->postForm($driver->loginUrl(), ['grant_type' => 'client_credentials', 'username' => '', 'password' => '']);
check('login without creds → 401 POS_ERROR', $badLogin['status'] === 401 && $badLogin['body']['code'] === 'POS_ERROR');

$token = $login['body']['access_token'];
$auth = $driver->authHeaders($token);
$noAuth = $mock->post($driver->orderStatusUrl('T1'), $accept, []);
check('status call without bearer → 401', $noAuth['status'] === 401);
$okAccept = $mock->post($driver->orderStatusUrl('T1'), $accept, $auth);
check('accept → 200 "status successfully changed"', $okAccept['status'] === 200 && str_contains($okAccept['body']['message'] ?? '', 'successfully'));
$badReject = $mock->post($driver->orderStatusUrl('T1'), ['status' => 'order_rejected'], $auth);
check('reject w/o reason → 400', $badReject['status'] === 400);
$prep = $mock->post($driver->preparedUrl('T1'), [], $auth);
check('preparation-completed → 200 {code:OK}', $prep['status'] === 200 && ($prep['body']['code'] ?? '') === 'OK');

echo "── Inbound: selectedToppings + children mapping ──".PHP_EOL;
$dispatchPayload = [
    'token'          => 'DISP-1',
    'expeditionType' => 'delivery',
    'products'       => [
        ['remoteCode' => 'KARAHI', 'name' => 'Karahi', 'quantity' => 1, 'paidPrice' => '1800.00',
            'selectedToppings' => [
                ['remoteCode' => 'NAAN', 'name' => 'Naan', 'type' => 'EXTRA', 'quantity' => 2, 'price' => '80.00',
                    'children' => [['remoteCode' => 'BUTTER', 'name' => 'Butter', 'quantity' => 1, 'price' => '20.00']]],
            ]],
    ],
];
$dispatch = $driver->parseIncomingOrder($dispatchPayload);
check('product paidPrice→paisa (1800.00→180000)', $dispatch->items[0]->unitPrice === 180000);
check('topping via selectedToppings', $dispatch->items[0]->modifiers[0]->remoteId === 'NAAN' && $dispatch->items[0]->modifiers[0]->unitPrice === 8000);
check('nested topping child', $dispatch->items[0]->modifiers[0]->modifiers[0]->remoteId === 'BUTTER');

echo "── Inbound: status mapping ──".PHP_EOL;
$cancel = $driver->parseIncomingStatus(['status' => 'ORDER_CANCELLED', 'message' => 'gone']);
check('ORDER_CANCELLED→cancelled', $cancel->status === 'cancelled' && $cancel->comment === 'gone');
check('ORDER_PICKED_UP→picked_up', $driver->parseIncomingStatus(['status' => 'ORDER_PICKED_UP'])->status === 'picked_up');

echo "── Inbound auth: Middleware JWT ──".PHP_EOL;
$secret = 'shared-staging-secret';
$goodJwt = Jwt::encode(['service' => 'middleware', 'exp' => time() + 300], $secret);
check('valid middleware JWT accepted', $driver->verifyInboundAuth(['authorization' => 'Bearer '.$goodJwt], $secret) === true);
check('wrong secret rejected', $driver->verifyInboundAuth(['authorization' => 'Bearer '.$goodJwt], 'other-secret') === false);
$noClaim = Jwt::encode(['service' => 'someoneelse', 'exp' => time() + 300], $secret);
check('missing service:middleware claim rejected', $driver->verifyInboundAuth(['authorization' => 'Bearer '.$noClaim], $secret) === false);
check('no auth header rejected', $driver->verifyInboundAuth([], $secret) === false);
check('verification skipped when no secret', $driver->verifyInboundAuth([], null) === true);
$expired = Jwt::encode(['service' => 'middleware', 'exp' => time() - 10], $secret);
check('expired token rejected', $driver->verifyInboundAuth(['authorization' => 'Bearer '.$expired], $secret) === false);

echo "── Domain clients over AuthorizedClient (token cached, mock) ──".PHP_EOL;
$cache = new ArrayCache();
$tokens = new TokenManager(new MockTransport(), ['ttl_buffer' => 120], $cache);
$apiCreds = new Credentials(platform: 'delivery_hero', username: 'u', password: 'p', sandbox: true, chainCode: 'CHAIN1');
$apiDriver = new DeliveryHeroDriver($apiCreds, $platformConfig);
$api = new AuthorizedClient($apiDriver, new MockTransport(), $tokens, $apiCreds);

$t1 = $tokens->token($apiCreds, $apiDriver);
check('token obtained + cached', ! empty($t1) && $cache->get($apiCreds->tokenCacheKey()) === $t1);

$store = new StoreClient($api, $apiDriver, $apiCreds);
$avail = $store->getAvailability('POSV1');
check('store: availability list per platform', ($avail[0]['platformKey'] ?? null) === 'FP_PK');
check('store: open → 200', $store->open('POSV1', 'FP_PK', '123456789') === []);
check('store: close → 200', $store->close('POSV1', 'FP_PK', '123456789', 'OTHER', 60) === []);
check('store: reachability echoes status', ($store->setReachability('POSV1', 'offline')['status'] ?? null) === 'offline');

$catalog = new CatalogClient($api, $apiDriver, $apiCreds);
check('catalog: submit → import id', ! empty($catalog->submitCatalog(['items' => ['x' => []]])['catalogImportId']));
check('catalog: centralized kitchen submit → import id', ! empty($catalog->submitCentralizedKitchenCatalog(['items' => []], 'FP_PK')['catalogImportId']));
check('catalog: import logs', array_key_exists('menuImportLogs', $catalog->importLogs('POSV1', ['limit' => '5'])));
check('catalog: enable items → 204', $catalog->enableItems('POSV1', ['i1', 'i2'], 'FP_PK') === []);
check('catalog: disable until NBD → 204', $catalog->disableItems('POSV1', ['i1'], 'FP_PK', 'ITEM', 'NEXT_BUSINESS_DAY') === []);
check('catalog: unavailable items', $catalog->unavailableItems('POSV1') === []);
check('catalog: platform vendors', array_key_exists('platformVendors', $catalog->platformVendors('POSV1')));
check('catalog: modify product', ! empty($catalog->modifyProduct('FP_PK', 'PVID1', ['availability' => 'INACTIVE'])['message']));
check('catalog: deprecated menu XML → SUBMITTED', ($catalog->submitMenuXml('POSV1', '<menu/>')['status'] ?? null) === 'SUBMITTED');
check('catalog: triggered menu XML → SUBMITTED', ($catalog->submitTriggeredMenuXml('VC', 'MID', '<menu/>')['status'] ?? null) === 'SUBMITTED');

$reports = new ReportClient($api, $apiDriver, $apiCreds);
check('reports: order ids', array_key_exists('orderIdentifiers', $reports->orderIds('accepted', 12)));
throws('reports: invalid status rejected', fn () => $reports->orderIds('delivered'));
check('reports: order detail', ! empty($reports->orderDetails('ORD-1')['order']));

$orders = new OrderClient($api, $apiDriver, $apiCreds);
check('orders: adjust prep time → 204', $orders->adjustPreparationTime('T1', '2026-01-01T00:10:00Z') === []);
throws('orders: adjust prep time w/o time → error', fn () => $orders->adjustPreparationTime('T1', ''));
check('orders: modify products → accepted', ! empty($orders->modifyProducts('T1', ['products' => []])['message']));

$noChain = new Credentials(platform: 'delivery_hero', username: 'u', password: 'p', sandbox: true);
$noChainStore = new StoreClient(new AuthorizedClient($apiDriver, new MockTransport(), $tokens, $noChain), $apiDriver, $noChain);
throws('missing chainCode → clear error', fn () => $noChainStore->getAvailability('POSV1'));

echo PHP_EOL.($fail === 0
    ? "ALL PASS — {$pass}/{$pass}".PHP_EOL
    : "FAILURES: {$fail}   (passed {$pass})".PHP_EOL);

exit($fail === 0 ? 0 : 1);
