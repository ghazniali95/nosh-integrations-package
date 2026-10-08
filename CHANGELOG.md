# Changelog

All notable changes to `nosh/omniconnect`. Versions follow [Semantic Versioning](https://semver.org).

## v1.1.0 — 2026-10-08

Hardening before the first production install, plus the order fields a POS needs to book foodpanda money correctly.

### Fixed
- **An order could be acknowledged without the app ever receiving it.** The `omniconnect_orders` row was written before the `IncomingOrderHandler` ran; if the handler threw, foodpanda re-sent, the re-delivery found the row, skipped the handler and acked. The handoff is now stamped `handled_at` only after the handler returns, and a re-delivery with `handled_at` still null runs the handler again. **Handlers must be idempotent on `orderToken`.** `OrderReceived` still fires once.
- **Webhook verification failed open on a blank secret.** A live install with no `OMNICONNECT_WEBHOOK_SECRET` accepted anyone's orders. A missing secret is now a `401`, except a single-install on the `mock` transport (local dev). `DeliveryHeroDriver::verifyInboundAuth()` returns false on an empty secret instead of true.
- **Status callbacks were not scoped to the vendor.** `posOrderStatus` found the order by `remoteOrderId` alone and used the default driver; it is now matched under the `{remoteId}` it was dispatched to (and the tenant, when resolved), through that tenant's driver. A cancel for an order we do not hold no longer reaches `IncomingOrderHandler::cancelled()` with an empty token.
- `subtotal` is read from `price.subTotal` (the products' sum), falling back to `totalNet`.
- A product's `variation` sent as an object (`{name}`) is read as its name.

### Changed
- **Auto-accept is queued.** A handler returning `'accepted'` now dispatches `Orders\AcceptOrderJob` on `OMNICONNECT_QUEUE` (retries/backoff from `retry_attempts` / `retry_backoff`) instead of calling foodpanda inside the webhook, so a slow platform can no longer turn our acknowledgement into a 5xx. The job carries the tenant id, never credentials.

### Added
- `Order`: `remoteId` (the POS vendor id from the webhook URL — map branches on this), `discountTotal`, `discounts[]` (`name`, `amount`, `type`, `sponsor`), `containerCharge`, `riderTip`, `collectFromCustomer`, `payRestaurant`, `paymentType`, `preOrder`, `expectedDeliveryTime`, `riderPickupTime`, `pickupTime`.
- `OrderItem`: `platformId` (foodpanda's product id), `remoteCode` (as sent, null when blank), `discount`.
- `Customer`: `lat`, `lng`; the delivery address is composed from `delivery.address` when the customer block has none.
- `omniconnect_orders` columns (new migration, nullable): `remote_id`, `handled_at`.

## v1.0.0 — 2026-08-19

Initial release: foodpanda (Delivery Hero POS Integration Middleware) — outbound order / catalog / store / report clients, inbound Plugin API with JWT verification, multi-tenant credentials, mock transport.
