# OmniConnect

**A Laravel package that connects a restaurant / POS system to foodpanda**
(via Delivery Hero's POS Integration Middleware).

It is **two-directional**:

- **Outbound** — your app *calls* foodpanda to accept/reject orders, mark them
  prepared or picked up, adjust prep time, modify products, push your menu,
  toggle item and store availability, and pull order reports.
- **Inbound** — foodpanda *calls your app* over a mountable, JWT-verified set of
  webhook routes (new orders, cancellations, availability changes, menu-import
  triggers, catalog-import results). OmniConnect verifies, normalizes, and
  hands them to your code.

It is **multi-tenant by design**: one installation serves many restaurants,
each connected to its **own** foodpanda account and webhook secret, fully
isolated from the others.

> A Nosh (RapidFlow) package. `composer require nosh/omniconnect`.

---

## Table of contents

1. [How it works](#1-how-it-works)
2. [Supported platforms & roadmap](#2-supported-platforms--roadmap)
3. [Requirements](#3-requirements)
4. [Installation](#4-installation)
5. [Getting your foodpanda credentials](#5-getting-your-foodpanda-credentials)
6. [Configuration reference](#6-configuration-reference)
7. [Sandbox vs. production (going live)](#7-sandbox-vs-production-going-live)
8. [Authentication & tokens](#8-authentication--tokens)
9. [Single-restaurant setup](#9-single-restaurant-setup)
10. [Multi-tenant setup](#10-multi-tenant-setup)
11. [Receiving orders (inbound) & the webhook URLs](#11-receiving-orders-inbound--the-webhook-urls)
12. [Handling received orders in your app](#12-handling-received-orders-in-your-app)
13. [Order management (outbound)](#13-order-management-outbound)
14. [Menu / catalog management (outbound)](#14-menu--catalog-management-outbound)
15. [Store management (outbound)](#15-store-management-outbound)
16. [Order report service (outbound)](#16-order-report-service-outbound)
17. [The canonical order model](#17-the-canonical-order-model)
18. [Events](#18-events)
19. [Database tables](#19-database-tables)
20. [Error handling](#20-error-handling)
21. [Security notes](#21-security-notes)
22. [Full endpoint coverage](#22-full-endpoint-coverage)
23. [Extending: add a new delivery platform](#23-extending-add-a-new-delivery-platform)
24. [Troubleshooting / FAQ](#24-troubleshooting--faq)
25. [License](#25-license)

---

## 1. How it works

foodpanda's platform is operated by **Delivery Hero**, which exposes a **POS
Integration Middleware**. There are two API surfaces:

| Surface | Who calls whom | What it is |
|---|---|---|
| **Integration Middleware API** | your app → foodpanda | You *call* foodpanda. OmniConnect gives you fluent clients for it. |
| **Plugin API** | foodpanda → your app | foodpanda *calls you* over HTTP. OmniConnect mounts the routes, verifies the caller, and hands you normalized data. |

OmniConnect wraps both. You interact with a single facade, `OmniConnect`, and
implement one optional handler to receive orders.

```
                    ┌──────────────────────── your Laravel app ───────────────────────┐
   foodpanda  ⇄     │  Plugin API routes  →  OmniConnect  →  your IncomingOrderHandler │
 (Delivery Hero)    │  OmniConnect facade →  Middleware API (accept / menu / store …) │
                    └──────────────────────────────────────────────────────────────────┘
```

Money is represented as **integer minor units (paisa)** throughout (e.g. Rs
18.00 → `1800`).

---

## 2. Supported platforms & roadmap

| Platform | Operated by | What it covers | Status |
|---|---|---|---|
| **foodpanda** | Delivery Hero — POS Integration Middleware | Orders (in/out), menu/catalog push, store & item availability, order reports | ✅ Available |
| **Careem** (Now / Food) | Careem | Delivery marketplace integration | 🚧 Rolling out |
| **Other aggregators** (Uber Eats, Deliveroo, …) | Various | Delivery marketplace integration | 🧭 By design — see §23 |

> The public API in this document is **platform-agnostic**. You build one
> canonical `Order`, catalog, and store call, and OmniConnect maps it to
> whichever platform's API — foodpanda today, others as they are onboarded.
> Adding a platform is a new driver + a config block, **not** a rewrite of your
> app (see §23).

---

## 3. Requirements

- PHP **8.1+**
- Laravel **10, 11, 12, or 13**
- A publicly reachable HTTPS URL for your app (so foodpanda can deliver
  webhooks) when you go live.

---

## 4. Installation

```bash
composer require nosh/omniconnect
```

Publish the config and migrations, then migrate:

```bash
php artisan vendor:publish --tag=omniconnect-config
php artisan vendor:publish --tag=omniconnect-migrations
php artisan migrate
```

This creates two tables — `omniconnect_credentials` (used only in multi-tenant mode)
and `omniconnect_orders` (a log of every order received, with idempotency).

The package auto-registers its service provider and the `OmniConnect` facade
(Laravel package discovery). No manual wiring needed.

---

## 5. Getting your foodpanda credentials

Each restaurant is onboarded by foodpanda / Delivery Hero and receives:

| You receive | Used for | Config field |
|---|---|---|
| **username + password** | `client_credentials` login → access token | `OMNICONNECT_USERNAME` / `OMNICONNECT_PASSWORD` |
| **webhook secret** | verifying inbound webhooks are genuinely from foodpanda | `OMNICONNECT_WEBHOOK_SECRET` |
| **POS vendor id(s)** (aka `remoteId`) | identifies each restaurant/branch | `OMNICONNECT_VENDOR_IDS` |
| **chain code** | groups a restaurant's vendors; used in catalog/store/report URLs | `OMNICONNECT_CHAIN_CODE` |

To obtain them, click **"Get Credentials"** on Delivery Hero's developer page
during onboarding. The **webhook secret differs between staging and
production** — keep both if you test on staging first.

> You do **not** need any of these to install and build against OmniConnect —
> a mock transport lets you develop the whole integration first (see §7). You
> only need real credentials to talk to the real foodpanda.

---

## 6. Configuration reference

Everything is driven by `config/omniconnect.php`, which reads environment
variables. The complete list:

```dotenv
# --- platform & mode ---
OMNICONNECT_PLATFORM=delivery_hero      # the delivery platform (only driver today)
OMNICONNECT_SANDBOX=true                # true = staging host, false = production host
OMNICONNECT_TRANSPORT=http              # 'http' = real calls, 'mock' = in-package fake (no creds)

# --- single-restaurant identity (used when OMNICONNECT_CREDENTIALS_DRIVER=env) ---
OMNICONNECT_USERNAME=
OMNICONNECT_PASSWORD=
OMNICONNECT_CHAIN_CODE=
OMNICONNECT_VENDOR_IDS=POS_VENDOR_1,POS_VENDOR_2   # comma-separated

# --- how credentials are resolved ---
OMNICONNECT_CREDENTIALS_DRIVER=env      # 'env' (one restaurant) | 'database' (multi-tenant) | 'callback'

# --- access-token cache (foodpanda tokens last ~30 min) ---
OMNICONNECT_TOKEN_CACHE=                # cache store name; blank = default store
OMNICONNECT_TOKEN_TTL_BUFFER=120        # refresh this many seconds before expiry

# --- inbound webhooks (foodpanda → you) ---
OMNICONNECT_WEBHOOK_ROUTES=true         # auto-mount the Plugin API routes
OMNICONNECT_WEBHOOK_PREFIX=omniconnect/webhooks
OMNICONNECT_WEBHOOK_SECRET=             # single-install shared secret (see §10 for multi-tenant)
OMNICONNECT_WEBHOOK_VERIFY=true         # verify the JWT foodpanda signs its calls with

# --- reliability ---
OMNICONNECT_TIMEOUT=30
OMNICONNECT_RETRY_ATTEMPTS=3
OMNICONNECT_QUEUE=omniconnect

# --- logging ---
OMNICONNECT_LOGGING_ENABLED=true
OMNICONNECT_LOG_CHANNEL=daily
OMNICONNECT_LOG_LEVEL=info

# --- hosts (pre-filled; override only if foodpanda gives you different ones) ---
OMNICONNECT_DH_SANDBOX_HOST=https://integration-middleware.stg.restaurant-partners.com
OMNICONNECT_DH_PRODUCTION_HOST=https://integration-middleware.restaurant-partners.com
```

> **Confirm the production host with foodpanda.** The staging host is confirmed
> from the Middleware spec; the production host is pre-filled as the same domain
> without the `.stg` segment. If your onboarding gives a region-specific host
> (e.g. an `.eu`/`.me` variant), set `OMNICONNECT_DH_PRODUCTION_HOST` accordingly.

---

## 7. Sandbox vs. production (going live)

Two independent switches:

- **`OMNICONNECT_TRANSPORT`** — `http` (talk to foodpanda over the network) or `mock`
  (an in-package fake foodpanda returning spec-shaped responses, so you can
  build and test with **no credentials**). Develop on `mock`, ship on `http`.
- **`OMNICONNECT_SANDBOX`** — `true` routes to foodpanda's **staging** host, `false`
  to **production**. Use your staging credentials + secret while `true`.

**Go-live checklist:**

1. `OMNICONNECT_TRANSPORT=http`
2. `OMNICONNECT_SANDBOX=false` (and confirm `OMNICONNECT_DH_PRODUCTION_HOST`)
3. Real `OMNICONNECT_USERNAME` / `OMNICONNECT_PASSWORD` (or per-tenant rows — §10)
4. Real `OMNICONNECT_WEBHOOK_SECRET` (production secret)
5. Your inbound routes reachable over public HTTPS (§11) and registered with foodpanda

No code changes are required to switch between mock/staging/production.

---

## 8. Authentication & tokens

You never manage tokens. OmniConnect exchanges the `username`/`password` at
foodpanda's `POST /v2/login` for a JWT (valid ~30 minutes), caches it (keyed
per restaurant), reuses it across calls, refreshes it shortly before expiry,
and — on a `401` — forces a fresh login and retries the call once.

Tune with `OMNICONNECT_TOKEN_CACHE` (which cache store) and `OMNICONNECT_TOKEN_TTL_BUFFER`
(how early to refresh).

---

## 9. Single-restaurant setup

If this installation serves **one** restaurant, use the `env` driver:

```dotenv
OMNICONNECT_CREDENTIALS_DRIVER=env
OMNICONNECT_USERNAME=your_username
OMNICONNECT_PASSWORD=your_password
OMNICONNECT_CHAIN_CODE=YOUR_CHAIN
OMNICONNECT_VENDOR_IDS=YOUR_POS_VENDOR_ID
OMNICONNECT_WEBHOOK_SECRET=your_webhook_secret
OMNICONNECT_TRANSPORT=http
OMNICONNECT_SANDBOX=false
```

Then just call the facade:

```php
use Nosh\OmniConnect\Facades\OmniConnect;

OmniConnect::orders()->accept($orderToken);
OmniConnect::catalog()->submitCatalog($catalog);
OmniConnect::store()->close('YOUR_POS_VENDOR_ID', 'FP_PK', $platformRestaurantId, 'TOO_BUSY_KITCHEN', 60);
```

---

## 10. Multi-tenant setup

To serve **many** restaurants — each with its own foodpanda account — use the
`database` driver and store one row per restaurant in `omniconnect_credentials`.

```dotenv
OMNICONNECT_CREDENTIALS_DRIVER=database
OMNICONNECT_TRANSPORT=http
OMNICONNECT_SANDBOX=false
# OMNICONNECT_USERNAME / OMNICONNECT_PASSWORD / OMNICONNECT_WEBHOOK_SECRET are NOT used in this mode
```

Create a restaurant's credentials (password and webhook secret are **encrypted
at rest** automatically):

```php
use Nosh\OmniConnect\Models\OmniConnectCredential;

OmniConnectCredential::create([
    'tenant_id'      => 'restaurant-42',      // YOUR id for this restaurant
    'platform'       => 'delivery_hero',
    'sandbox'        => false,
    'username'       => 'fp_username_42',
    'password'       => 'fp_password_42',      // encrypted
    'chain_code'     => 'CHAIN_42',
    'vendor_ids'     => ['POS_VENDOR_42'],     // the POS vendor id(s) foodpanda assigned
    'webhook_secret' => 'fp_webhook_secret_42',// encrypted
]);
```

**Outbound** — name the restaurant; OmniConnect resolves its account and caches
its token separately (no cross-account bleed):

```php
OmniConnect::for('restaurant-42')->orders()->accept($orderToken);
OmniConnect::for('restaurant-42')->catalog()->enableItems('POS_VENDOR_42', ['item-1'], 'FP_PK');
```

**Inbound** — OmniConnect routes each incoming webhook to the right restaurant
by the `{remoteId}` (POS vendor id) in the URL: it finds the tenant whose
`vendor_ids` contains it, **verifies the JWT against that tenant's own webhook
secret**, tags the stored order with `tenant_id`, and (if you auto-accept)
accepts through that tenant's account. An unrecognized vendor is rejected
(`401`, fail-closed).

### Custom resolution (your own tables)

If you keep credentials in your own models instead of `omniconnect_credentials`,
register resolvers in a service provider's `boot()`:

```php
// Outbound: tenant id → credentials
OmniConnect::resolveCredentialsUsing(fn ($tenant, $platform) =>
    MyRestaurant::find($tenant)?->toOmniConnectCredentials());

// Inbound: remoteId (POS vendor id) → the owning restaurant's credentials
OmniConnect::resolveInboundUsing(fn ($remoteId, $platform) =>
    MyRestaurant::whereJsonContains('fp_vendor_ids', $remoteId)->first()?->toOmniConnectCredentials());
```

A resolver may return a `Nosh\OmniConnect\Data\Credentials`, an array, an object
with a `toCredentials()` method, a tenant id, or `null`.

---

## 11. Receiving orders (inbound) & the webhook URLs

When `OMNICONNECT_WEBHOOK_ROUTES=true` (default), OmniConnect mounts these routes
under `OMNICONNECT_WEBHOOK_PREFIX` (default `omniconnect/webhooks`), behind a middleware
that verifies foodpanda's signed JWT:

| Method & path (relative to your app URL) | Purpose |
|---|---|
| `POST  {prefix}/order/{remoteId}` | New order dispatch |
| `PUT   {prefix}/remoteId/{remoteId}/remoteOrder/{remoteOrderId}/posOrderStatus` | Cancellation / picked-up / modification result |
| `PUT   {prefix}/remoteId/{remoteId}/availability` | Vendor availability change |
| `GET   {prefix}/menuimport/{remoteId}` | foodpanda requests your menu |
| `POST  {prefix}/catalog-import-callback` | Catalog-import status |

**Give foodpanda your base URL** during onboarding, e.g.
`https://your-app.com/omniconnect/webhooks`. They will call the paths above.
`{remoteId}` is the POS vendor id; `{remoteOrderId}` is the id **you** returned
when acknowledging the order (see §12).

**Inbound authentication.** Every inbound call carries a JWT (HS512) signed with
your webhook secret and a `service: middleware` claim; OmniConnect verifies
both. In multi-tenant mode the secret is resolved per restaurant. **A missing
secret fails closed (`401`)** — the only exception is a single-install on the
`mock` transport with no secret at all (local dev). To mount the
routes yourself instead, set `OMNICONNECT_WEBHOOK_ROUTES=false` and register them from
`src/Http/routes.php` inside your own route group.

**Idempotency.** foodpanda may re-deliver a dispatch. OmniConnect keys
`omniconnect_orders` on `(platform, order_token)`, so a re-delivery returns the same
`remoteOrderId` and `OrderReceived` fires only once.

**Retried handoff.** `handled_at` is stamped only after your
`IncomingOrderHandler` returns. If it throws, foodpanda gets a 5xx, re-sends,
and the re-delivery runs your handler again — an order is never acknowledged
to foodpanda without your app having taken it. **Your handler must therefore be
idempotent on `orderToken`** (e.g. a unique key on it in your own orders table).

---

## 12. Handling received orders in your app

Received orders are normalized to a canonical `Order` (§17), stored in
`omniconnect_orders`, and handed to your application in two ways.

### A) The `IncomingOrderHandler` (recommended)

Bind an implementation and OmniConnect calls it for every new order and every
cancellation:

```php
use Nosh\OmniConnect\Contracts\IncomingOrderHandler;
use Nosh\OmniConnect\Data\Order;

class FoodpandaOrderHandler implements IncomingOrderHandler
{
    public function handle(Order $order): ?string
    {
        // Persist to your own system, print to the kitchen, deduct inventory…
        // Return 'accepted' to have OmniConnect auto-accept with foodpanda
        // (queued as AcceptOrderJob on OMNICONNECT_QUEUE, so the webhook
        // answers at once), or null to decide later (accept()/reject() yourself).
        // May run more than once for the same order — see "Retried handoff".
        return null;
    }

    public function cancelled(Order $order): void
    {
        // Reverse inventory, notify the kitchen, etc.
    }
}
```

Register it in a service provider:

```php
$this->app->bind(
    \Nosh\OmniConnect\Contracts\IncomingOrderHandler::class,
    \App\Foodpanda\FoodpandaOrderHandler::class,
);
```

### B) Events

Whether or not you bind a handler, OmniConnect fires events (§18). Listen to
`OrderReceived`, `OrderCancelled`, `OrderStatusUpdated`, etc.

### The acknowledge handshake

The package answers foodpanda's dispatch with a **`remoteOrderId`** (your
POS-side order id — by default `PANDA-{id}`). foodpanda uses that id to address
later status callbacks. This is automatic; you don't build the response.

### Auto-close window

foodpanda auto-cancels an order if it isn't accepted/rejected within a
platform-specific window (typically minutes). Accept or reject promptly (see
§13). OmniConnect's inbound route acknowledges immediately; your acceptance
decision should follow quickly (synchronously in the handler, or via a queued
job you dispatch from it).

---

## 13. Order management (outbound)

All methods take the order's **`orderToken`** (from the dispatch) and return the
platform's decoded response.

```php
use Nosh\OmniConnect\Facades\OmniConnect;
use Nosh\OmniConnect\Support\RejectReason;

// Accept — acceptanceTime is auto-filled (RFC-3339) if you don't pass one
OmniConnect::orders()->accept($orderToken);
OmniConnect::orders()->accept($orderToken, ['acceptance_time' => '2026-01-01T17:45:00Z', 'remote_order_id' => 'POS-123']);

// Reject — reason MUST be one of the RejectReason constants (see below)
OmniConnect::orders()->reject($orderToken, RejectReason::ITEM_UNAVAILABLE, ['message' => 'Out of tomatoes']);

// Kitchen lifecycle
OmniConnect::orders()->markPrepared($orderToken);   // food ready (Delivery-Hero-delivered orders)
OmniConnect::orders()->markPickedUp($orderToken);   // vendor-delivery / pickup orders

// Adjust the prep/pickup time (logistics-delivery orders), RFC-3339
OmniConnect::orders()->adjustPreparationTime($orderToken, '2026-01-01T18:05:00Z');

// Modify the order's products (e.g. remove an out-of-stock item)
OmniConnect::orders()->modifyProducts($orderToken, [
    'products' => [
        ['id' => 'PLATFORM_ITEM_ID', 'remoteCode' => 'YOUR_SKU', 'modification' => ['type' => 'REMOVAL']],
    ],
]);
```

Multi-tenant: prefix any of the above with `->for($tenantId)`, e.g.
`OmniConnect::for('restaurant-42')->orders()->accept($orderToken)`.

**Per-order callback URLs.** foodpanda includes per-order callback URLs in the
dispatch payload. OmniConnect automatically targets the correct one for each
action (accept/reject/picked-up/prepared/adjust/modify), falling back to the
standard endpoint. You can override with `['callback_url' => '…']` in `$opts`.

**Rejection reasons.** Use a constant from
`Nosh\OmniConnect\Support\RejectReason` (an invalid reason throws). The full set
(from the spec) includes: `ITEM_UNAVAILABLE`, `CLOSED`, `TOO_BUSY`, `NO_COURIER`,
`MENU_ACCOUNT_SETTINGS`, `TECHNICAL_PROBLEM`, `ADDRESS_INCOMPLETE_MISSTATED`,
`BAD_WEATHER`, `OUTSIDE_DELIVERY_AREA`, and more — `RejectReason::all()` returns
them all.

**Inbound order status.** Cancellations, picked-up, product-modification results
(`PRODUCT_ORDER_MODIFICATION_SUCCESSFUL` / `_FAILED`), courier-arrived and
rider-waiting warnings arrive as inbound webhooks and surface via the
`OrderStatusUpdated` (and `OrderCancelled`) events.

---

## 14. Menu / catalog management (outbound)

`$posVendorId` is the POS vendor id; `$globalEntityId` is foodpanda's market id
(e.g. `FP_PK`). The `$chainCode` argument is optional — it defaults to the
resolved restaurant's chain code.

```php
// Push the full menu (Delivery Hero Catalog schema; pass a structured array)
OmniConnect::catalog()->submitCatalog($catalogArray);

// Centralized-kitchen catalog (platform vendor ids under a global entity)
OmniConnect::catalog()->submitCentralizedKitchenCatalog($catalogArray, 'FP_PK');

// Item availability — the common "out of stock / back in stock" flows
OmniConnect::catalog()->enableItems($posVendorId, ['sku-1', 'sku-2'], 'FP_PK');
OmniConnect::catalog()->disableItems($posVendorId, ['sku-1'], 'FP_PK');
OmniConnect::catalog()->disableItems($posVendorId, ['sku-1'], 'FP_PK', 'ITEM', 'NEXT_BUSINESS_DAY');
OmniConnect::catalog()->disableItems($posVendorId, ['sku-1'], 'FP_PK', 'ITEM', '2026-01-02T09:00:00Z');
// (type is 'ITEM' or 'TOPPING'; $until = null | 'NEXT_BUSINESS_DAY' | an ISO-8601 timestamp)

// Read state / metadata
OmniConnect::catalog()->importLogs($posVendorId, ['limit' => '10', 'sort' => 'desc']);
OmniConnect::catalog()->unavailableItems($posVendorId);
OmniConnect::catalog()->platformVendors($posVendorId);

// Modify a single product's details (Pandora platform)
OmniConnect::catalog()->modifyProduct('FP_PK', $platformVendorId, ['availability' => 'INACTIVE']);

// Deprecated XML menu import (prefer submitCatalog)
OmniConnect::catalog()->submitMenuXml($posVendorId, $xmlString);
OmniConnect::catalog()->submitTriggeredMenuXml($vendorCode, $menuImportId, $xmlString);
```

**Responding to a menu-import request.** When foodpanda hits
`GET {prefix}/menuimport/{remoteId}` (the `MenuImportRequested` event, carrying
`vendorCode` and `menuImportId`), respond asynchronously by pushing your menu —
either `submitCatalog()` (recommended) or, for the legacy XML flow,
`submitTriggeredMenuXml($vendorCode, $menuImportId, $xml)`.

The catalog body follows Delivery Hero's Catalog schema. OmniConnect passes it
through as a structured array — build it from your menu; the item-availability
helpers above compose the common enable/disable bodies for you.

---

## 15. Store management (outbound)

```php
// Read availability per platform (returns one entry per platform the vendor is on)
$states = OmniConnect::store()->getAvailability($posVendorId);

// Open / close a platform storefront.
// platformKey + platformRestaurantId come from the getAvailability() response;
// only change a platform whose `changeable` is true.
OmniConnect::store()->open($posVendorId, 'FP_PK', $platformRestaurantId);
OmniConnect::store()->close($posVendorId, 'FP_PK', $platformRestaurantId, 'TOO_BUSY_KITCHEN', 60);
// close(): $closedReason from the response's closingReasons; $closingMinutes for CLOSED_UNTIL;
//          optional $availabilityState (default 'CLOSED_UNTIL', or 'CLOSED' / 'CLOSED_TODAY').

// Deprecated POS reachability toggle ('online' | 'offline')
OmniConnect::store()->setReachability($posVendorId, 'offline');
```

---

## 16. Order report service (outbound)

Reconcile orders (e.g. after downtime):

```php
// $status: 'accepted' | 'cancelled'; $pastNumberOfHours: 1–24
$ids = OmniConnect::reports()->orderIds('accepted', 24);
$one = OmniConnect::reports()->orderDetails($orderId);
```

---

## 17. The canonical order model

Inbound orders are mapped from foodpanda's payload to a platform-neutral
`Nosh\OmniConnect\Data\Order`, so your code never touches raw platform fields:

| Property | Notes |
|---|---|
| `orderToken` | foodpanda's order id — used on all outbound order calls |
| `orderCode` | short human code |
| `vendorId` | the POS vendor id (`platformRestaurant.id`) |
| `status` | `received` \| `accepted` \| `rejected` \| `prepared` \| `picked_up` \| `cancelled` |
| `expeditionType` | `delivery` \| `pickup` |
| `customer` | `Customer{ name, phone, email, address }` |
| `items` | `OrderItem[]` — each with nested `modifiers` (toppings) |
| `subtotal`, `deliveryFee`, `total` | **paisa** (integer minor units) |
| `currency`, `comment`, `paidOnline`, `placedAt` | |
| `test` | a foodpanda **test order** — do **not** send it to the kitchen |
| `callbackUrls` | per-order URLs foodpanda gave for status updates |
| `raw` | the untouched payload (audit / anything not mapped) |
| `remoteId` | the POS vendor id the webhook was addressed to — map branches on this |
| `discountTotal`, `discounts` | paisa; each `{name, amount, type, sponsor}` (`sponsor`: who funds it, when sent) |
| `containerCharge`, `riderTip`, `collectFromCustomer`, `payRestaurant` | paisa; `payRestaurant` is null when not sent |
| `paymentType`, `preOrder` | |
| `expectedDeliveryTime`, `riderPickupTime`, `pickupTime` | ISO-8601 strings as sent |

Each `OrderItem` has `remoteId` (your SKU — bind recipes/stock to this, never to
price), `name`, `quantity`, `unitPrice`, `total` (paisa), `variation`, and
nested `modifiers`. It also keeps `remoteCode` (exactly as sent, null when
blank), `platformId` (foodpanda's own product id — the only stable key for a
menu built on foodpanda rather than pushed from your POS) and `discount`.

---

## 18. Events

Listen to any of these (`Nosh\OmniConnect\Events\…`):

| Event | Fired when |
|---|---|
| `OrderReceived` | a new order was dispatched and normalized |
| `OrderAccepted` / `OrderRejected` | you accepted / rejected an order (outbound) |
| `OrderCancelled` | foodpanda notified a cancellation |
| `OrderStatusUpdated` | any inbound status (cancelled, picked-up, modification result, courier, rider warnings) — carries the raw status enum |
| `VendorAvailabilityChanged` | foodpanda pushed a vendor availability change (`isOpen()` helper) |
| `MenuImportRequested` | foodpanda asked for your menu (carries `vendorCode`, `menuImportId`) |
| `CatalogImportStatusReceived` | a catalog import you submitted reported its result |
| `CredentialsMissing` | no credentials resolved for a tenant/platform |

---

## 19. Database tables

- **`omniconnect_credentials`** (multi-tenant) — one row per restaurant:
  `tenant_id`, `platform`, `sandbox`, `username`, `password` *(encrypted)*,
  `chain_code`, `vendor_ids` *(JSON)*, `webhook_secret` *(encrypted)*.
- **`omniconnect_orders`** — every received order: `platform`, `tenant_id`,
  `order_token`, `remote_order_id`, `order_code`, `vendor_id`, `remote_id`, `status`,
  `expedition_type`, `total` *(paisa)*, `currency`, `paid_online`,
  `reject_reason`, `payload` *(JSON, the raw dispatch)*, and lifecycle
  timestamps incl. `handled_at`. Status callbacks are matched under the
  `{remoteId}` the order was dispatched to. Unique on `(platform, order_token)` for idempotency.

---

## 20. Error handling

Outbound calls throw `Nosh\OmniConnect\Exceptions\OmniConnectException` on any non-2xx
response (after the automatic 401 re-login + one retry), with foodpanda's error
message. Specific subtypes:

- `CredentialsMissingException` — no credentials for the tenant/platform.
- `AuthenticationException` — login rejected / no token obtainable.

```php
use Nosh\OmniConnect\Exceptions\OmniConnectException;

try {
    OmniConnect::for($tenant)->orders()->accept($orderToken);
} catch (OmniConnectException $e) {
    report($e);   // e.g. surface to staff, retry, or fall back
}
```

Inbound routes return foodpanda's expected status codes automatically
(`200`/`202` acknowledge, `400` invalid, `401` unauthorized).

---

## 21. Security notes

- **Never commit credentials.** Keep `OMNICONNECT_*` in `.env`; per-tenant
  `password` and `webhook_secret` are encrypted at rest via Laravel's encrypter
  (requires a stable `APP_KEY`).
- **Keep `OMNICONNECT_WEBHOOK_VERIFY=true`** in production so only genuinely-signed
  foodpanda calls are accepted. In multi-tenant mode, an unknown vendor is
  rejected (fail-closed).
- Access tokens are cached per restaurant and never logged.
- Bind recipes/inventory to item **`remoteId` (SKU)**, never to price.

---

## 22. Full endpoint coverage

**Outbound (Integration Middleware API):** login; order status
(accept / reject / picked-up); mark prepared; adjust preparation time; modify
order products; vendor availability (get / open / close); POS reachability
*(deprecated)*; submit catalog; submit centralized-kitchen catalog; catalog
import logs; item availability (enable / disable / until-next-business-day /
until-timestamp); unavailable items; platform vendors; modify product details;
XML menu import *(deprecated, ×2)*; order-report ids; order-report details.

**Inbound (Plugin API):** order dispatch; order status update; vendor
availability; menu-import trigger; catalog-import callback.

Every operation foodpanda's documentation offers, across Order management,
Catalog management, Store management, Order report service, and Catalog import
for centralized kitchen, is implemented.

---

## 23. Extending: add a new delivery platform

OmniConnect is **platform-driven**. Everything specific to foodpanda lives in
one class — `DeliveryHeroDriver` — behind the `DeliveryPlatformDriver`
interface. The domain clients (`OrderClient`, `CatalogClient`, `StoreClient`,
`ReportClient`), the inbound intake, the webhook routes, the token manager, the
events, and the multi-tenant layer are all **platform-neutral** and never change.

A driver is a pure mapper. It declares, for its platform:

- **Auth** — the login URL, the login params, how to read the token, the auth header.
- **Outbound** — the URL + request body for each action (accept/reject an order,
  mark prepared/picked-up, adjust prep time, push catalog, toggle availability,
  pull reports).
- **Inbound** — how to parse an incoming order/status into the canonical `Order`,
  and how to verify the webhook's authenticity.

To add a platform (e.g. **Careem**, **Uber Eats**):

1. Create a driver implementing `Nosh\OmniConnect\Contracts\DeliveryPlatformDriver`
   that maps the **canonical `Order`** (and catalog/store calls) to that platform's
   API and parses its payloads/webhooks back into OmniConnect's neutral shapes.
2. Register it in `config/omniconnect.php` under `platforms` with its hosts:

   ```php
   'platforms' => [
       'delivery_hero' => [ /* foodpanda — built in */ ],
       'careem' => [
           'label'  => 'Careem (Now / Food)',
           'driver' => \App\OmniConnect\CareemDriver::class,
           'hosts'  => ['sandbox' => '…', 'production' => '…'],
       ],
   ],
   ```

3. Done — you use it through the **exact same API**:

   ```php
   OmniConnect::platform('careem')->for($tenantId)->orders()->accept($orderToken);
   ```

Because the order your app builds and the catalog/store calls you make are
platform-neutral, adding a marketplace never changes your application code — only
a driver is added. This is the seam that makes OmniConnect multi-platform by
design.

---

## 24. Troubleshooting / FAQ

**Inbound webhooks return 401.** The JWT didn't verify, or no webhook secret is
configured (fail-closed outside the mock transport). Check the
`OMNICONNECT_WEBHOOK_SECRET` (or the tenant's `webhook_secret`) matches what foodpanda
issued for that environment (staging vs production secrets differ). During local
development you can set `OMNICONNECT_WEBHOOK_VERIFY=false`.

**Outbound calls fail with a 401 loop / "login failed".** Wrong
`username`/`password`, or `OMNICONNECT_SANDBOX` doesn't match the environment your
credentials belong to.

**"No platform credentials configured".** In `database` mode there is no
`omniconnect_credentials` row for that tenant/platform; in `env` mode
`OMNICONNECT_USERNAME`/`OMNICONNECT_PASSWORD` are blank.

**Unknown vendor rejected on inbound.** The `remoteId` in the webhook URL isn't
in any tenant's `vendor_ids`. Add it to the correct restaurant's row.

**Orders arrive but nothing happens.** Bind an `IncomingOrderHandler` or listen
to the `OrderReceived` event (§12). Without either, orders are stored as
`received` for you to act on.

**Money looks 100× too big/small.** All amounts are integer **paisa** (Rs 18.00
= `1800`). Divide by 100 for display.

**Can I run before I have credentials?** Yes — set `OMNICONNECT_TRANSPORT=mock` and
build/test the entire integration; flip to `http` when credentials arrive.

---

## 25. License

MIT © Nosh (a RapidFlow product).

```bash
composer require nosh/omniconnect
```
