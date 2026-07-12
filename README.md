# ez-ecommerce

A modular, headless e-commerce engine for Laravel.

> **Origin story:** This package was totally vibe-coded — born from long back-and-forth sessions between me and my two best friends: **ChatGPT 5.5 High** and **Claude Opus 4.8**. They helped shape the architecture, argued about idempotency at 2 AM, and never once confessed to invading my privacy (which I appreciate, or at least they’re polite about it). What you’re holding is a real Laravel package that came out of that collaboration — not a tutorial repo, not a demo cart, an actual engine you can ship behind your own storefront, mobile app, or admin panel.

**For AI coding agents (Cursor, Copilot, etc.):** read [`AGENTS.md`](AGENTS.md) first. It has the locked rules, file map, and “please don’t break the vibe” checklist.

---

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [60-second example](#60-second-example)
- [What you CAN use today](#what-you-can-use-today)
- [What you CANNOT expect yet](#what-you-cannot-expect-yet)
- [Architecture](#architecture)
- [Facade & managers](#facade--managers)
- [REST API](#rest-api)
- [Payment drivers](#payment-drivers)
- [Feature flags](#feature-flags)
- [Configuration reference](#configuration-reference)
- [Artisan commands & scheduler](#artisan-commands--scheduler)
- [Extending the engine](#extending-the-engine)
- [Testing](#testing)
- [Test coverage honesty](#test-coverage-honesty)
- [Sprint backlog (not built yet)](#sprint-backlog-not-built-yet)
- [License](#license)

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.2+ |
| Laravel | 11, 12, or 13 |
| Database | MySQL, PostgreSQL, or SQLite |

Optional Composer packages (payment drivers):

```bash
composer require stripe/stripe-php          # Stripe
composer require paypal/paypal-checkout-sdk # PayPal
```

---

## Installation

```bash
composer require ez-ecommerce/ez-ecommerce
php artisan commerce:install
php artisan migrate
```

- `commerce:install` publishes **config** and **translations**.
- Migrations load automatically from the package — there is **no** `commerce:migrate` command. Your app runs `php artisan migrate` like any other package.

Copy env vars from [`.env.example`](.env.example):

```dotenv
COMMERCE_CURRENCY=AED
COMMERCE_TAX_RATE=0.05
COMMERCE_SHIPPING_FLAT_MINOR=1000
COMMERCE_PAYMENT_DRIVER=manual
COMMERCE_API_TOKEN=your-strong-token
COMMERCE_API_ALLOW_UNAUTHENTICATED=false
COMMERCE_INBOUND_WEBHOOK_SECRET=
COMMERCE_INBOUND_WEBHOOK_ALLOW_UNSIGNED=false
COMMERCE_WEBHOOK_SECRET=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
```

---

## 60-second example

```php
use EzEcommerce\Facades\EzEcommerce;

// 1. Guest cart
['cart' => $cart, 'guest_token' => $token] = EzEcommerce::cart()->createGuest('AED');

// 2. Add a variant (implement Purchasable via ProductVariant)
EzEcommerce::cart()->addItem($cart, $variant, 2);

// 3. Calculate totals (shipping + tax + discounts)
$cart = EzEcommerce::cart()->calculateTotals($cart, shippingMethod: 'flat');

// 4. Checkout (idempotent)
$result = EzEcommerce::checkout()->for($cart)
    ->shippingMethod('flat')
    ->paymentMethod('manual')
    ->place(
        idempotencyKey: $request->header('Idempotency-Key'),
        expectedTotalsHash: EzEcommerce::cart()->totalsHash($cart, 'flat'),
    );

$order   = $result->order;
$payment = $result->payment;
$status  = $result->status; // CheckoutStatus enum
```

---

## What you CAN use today

These paths are implemented, wired, and covered by at least one test on the happy path.

### Core commerce loop

| Capability | How | Notes |
|------------|-----|-------|
| Guest carts | `EzEcommerce::cart()->createGuest()` | Returns `guest_token` for API/header auth |
| Cart items | `addItem`, `updateItem`, `removeItem` | Optimistic locking via `expected_version` |
| Cart totals | `calculateTotals`, `totalsHash` | Flat shipping + simple % tax + adjustments |
| Discount codes | `applyDiscount` / `removeDiscount` on CartManager + API | Percent, fixed; date validation |
| Checkout | `checkout()->for($cart)->place()` | Idempotent; returns `CheckoutResult` |
| Cart merge | `CartManager::merge()` + `POST /cart/merge` | Guest → customer cart on login |
| Orders | Snapshots on line items | Immutable product data at purchase time |
| Inventory | Reserve → commit on payment | Signed movements; race-safe expiry release |
| Manual capture | `CapturePayment` action / API | After `manual` gateway pending session |
| Payment reconciliation | `commerce:reconcile-payments` / `commerce:reconcile-refunds` | Operator tools for `unknown` PSP attempts |
| Order finalization recovery | `commerce:reconcile-finalizations` | Retry inventory commit after capture succeeded |
| Fulfillment | `OrderManager::fulfill()` / API | Updates fulfillment projection |
| Refunds | `RefundPayment` / API | Financial refund only (not returns) |
| Returns | Actions: create → receive → restock | Separate from refund |
| Idempotency | Checkout + stock receive | `Idempotency-Key` header on API checkout |

### Pricing

- `DefaultPriceResolver` with precedence: **customer → customer_group → price_list → sale → base**
- Integer minor units via `brick/money`
- `Purchasable` contract has **no** `price()` — always use `PriceResolver`

### Payments (drivers)

| Driver | Status | Use case |
|--------|--------|----------|
| `manual` | Production-ready | Admin capture, B2B workflows |
| `null` | Production-ready | Free / zero-total orders only |
| `fake` | Test-only | Deterministic test doubles |
| `net_terms` | B2B-ready | Defers capture; stores payment terms on order |
| `stripe` | Partial | Sessions; Stripe signature webhook verify when secret set |
| `paypal` | Partial | HTTP checkout; inbound webhooks via shared secret |
| `telr` | Partial | Order creation + refund HTTP; capture optimistic |

### REST API (`api/ez-commerce/v1`)

Enabled when `features.api` is `true` (default). See [REST API](#rest-api) for the full route table.

| Area | Status |
|------|--------|
| Products, guest cart, checkout | Public / guest-token auth |
| Orders (show, capture, fulfill, refund, retry-payment) | Bearer token |
| Returns (create, receive, restock, mark-damaged) | Bearer token |
| Customers + addresses | Bearer token |
| Stores, companies, vendors, subscriptions | Bearer token |
| Cart merge | Bearer token + guest token in body |
| Inbound webhooks (`stripe`, `paypal`, `telr`) | Signature / shared secret |

### Optional modules

| Module | What works | Limit |
|--------|------------|-------|
| **Subscriptions** | CRUD API, `BillSubscriptionPeriod` on renew command | Manual capture billing; no PSP dunning |
| **Marketplace** | Commission rows, vendor API, `vendor_id` on variants | No payout automation |
| **Multi-store** | `StoreContext`, `store_id`, stores API, `X-Commerce-Store` | No per-store policy engine |
| **B2B** | Companies API, `net_terms`, payment terms on order | No credit limits |
| **Outbound webhooks** | Outbox + `DeliverWebhookJob` + config/DB endpoints | Host runs queue worker |
| **Inbound webhooks** | `POST /webhooks/{gateway}` + `ReconcilePayment` | PayPal uses shared secret, not native verify |

### Artisan commands

```bash
php artisan commerce:install
php artisan commerce:release-expired-reservations
php artisan commerce:renew-subscriptions
php artisan commerce:purge-expired-carts
php artisan commerce:purge-idempotency-records
php artisan commerce:reconcile-payments --list
php artisan commerce:reconcile-refunds --list
php artisan commerce:reconcile-finalizations --list
```

---

## What you CANNOT expect yet

### No built-in UI

This is a **headless engine**. No storefront, admin panel, product editor, or checkout page. You build those in your app.

### Security (production checklist)

| Variable | Purpose |
|----------|---------|
| `COMMERCE_API_TOKEN` | Required for order/admin API; empty token → **503** on protected routes |
| `COMMERCE_API_ALLOW_UNAUTHENTICATED` | `true` only for local dev — opens admin routes without token |
| `COMMERCE_INBOUND_WEBHOOK_SECRET` | Required for PayPal/Telr webhooks (header `X-Commerce-Webhook-Secret`) |
| `STRIPE_WEBHOOK_SECRET` | Required for Stripe webhooks (`Stripe-Signature` header) |
| Guest checkout | `X-Guest-Cart-Token` on cart mutations + checkout |

`fake` / `null` / `manual` inbound webhook gateways register only in `local` and `testing`.

### Remaining API gaps

- Catalog update/delete endpoints
- Product variant-only CRUD

### Remaining product gaps

| Item | Status |
|------|--------|
| Automated PSP payout transfers | Payout API marks commissions paid; no bank transfer |
| `currency.rounding` config | Defined, never read |

### Unwired by design

- `OrderManager` (fulfill) is **not** on the `EzEcommerce` facade — use API or DI

---

## Architecture

```
┌─────────────┐     ┌──────────────┐     ┌─────────────┐
│  Your App   │────▶│ EzEcommerce  │────▶│ commerce_*  │
│  (API/UI)   │     │   Facade     │     │   tables    │
└─────────────┘     └──────┬───────┘     └─────────────┘
                           │
         ┌─────────────────┼─────────────────┐
         ▼                 ▼                 ▼
    CartManager      CheckoutManager    InventoryManager
         │                 │                 │
         ▼                 ▼                 ▼
    calculateTotals    PlaceOrder      ReserveInventory
                           │
              ┌────────────┴────────────┐
              ▼                         ▼
      DB transaction            Payment session
      (order, reserve)          (OUTSIDE txn)
```

### Design rules (non-negotiable)

1. **Payments never run inside DB transactions.**
2. **Refunds ≠ returns ≠ restock** — three separate workflows.
3. **Polymorphic types use morph aliases** (`commerce_product_variant`), not class names.
4. **Checkout is idempotent** — always pass a stable `idempotencyKey`.
5. **Money is integers** — minor units only.

---

## Facade & managers

### `EzEcommerce::` facade

```php
EzEcommerce::cart()       // CartManager
EzEcommerce::checkout()   // CheckoutManager → CheckoutBuilder
EzEcommerce::catalog()    // CatalogManager
EzEcommerce::inventory()   // InventoryManager
EzEcommerce::orders()     // OrdersManager
EzEcommerce::morphMap([...]) // Register morph aliases
```

### CartManager

| Method | Description |
|--------|-------------|
| `createGuest($currency, $guestToken?)` | New guest cart + token |
| `addItem($cart, $purchasable, $qty, $version?)` | Add line |
| `updateItem($cart, $item, $qty, $version?)` | Change quantity |
| `removeItem($cart, $item, $version?)` | Remove line |
| `applyDiscount($cart, $code, $version?)` | Apply promo code |
| `removeDiscount($cart, $code?, $version?)` | Remove promo adjustment |
| `calculateTotals($cart, $shipping?, $address?, $version?)` | Recalc all totals |
| `totalsHash($cart, $shipping?)` | Hash for checkout optimistic lock |
| `merge($guestCart, $customerCart)` | Merge guest into customer cart |

### CheckoutBuilder

```php
EzEcommerce::checkout()->for($cart)
    ->shippingAddress($address)
    ->billingAddress($address)
    ->shippingMethod('flat')
    ->paymentMethod('manual')
    ->customerIdentity($identity)
    ->place($idempotencyKey, $expectedTotalsHash);
```

### CatalogManager

`findProductBySlug`, `findVariantByPublicId`, `findVariantBySku`

### InventoryManager

`receiveStock($warehouse, $stockable, $qty, $idempotencyKey?)`, `releaseExpiredReservations()`

### OrdersManager

`findByPublicId($id)`, `recalculatePaymentStatus($order)`, `recalculateFulfillmentStatus($order)`

### OrderManager (not on facade)

`fulfill($order, $orderItem, $quantity)` — use via DI or API.

---

## REST API

**Base URL:** `/api/ez-commerce/v1` (configurable via `ez-ecommerce.api.prefix`)

### Authentication

| Route group | Auth |
|-------------|------|
| Products, `POST /cart/guest` | Public |
| Cart mutations | `X-Guest-Cart-Token` |
| `POST /checkout` | `X-Guest-Cart-Token` + `Idempotency-Key` |
| Orders, returns, customers, admin, cart merge | `Authorization: Bearer {COMMERCE_API_TOKEN}` or `X-Commerce-Api-Token` |
| Inbound webhooks | Stripe signature or `X-Commerce-Webhook-Secret` |

### Public & guest routes

| Method | Path | Body / headers |
|--------|------|----------------|
| `GET` | `/categories` | — |
| `GET` | `/categories/{id}` | — |
| `GET` | `/categories/{id}/products` | Paginated |
| `GET` | `/shipping-methods` | Available shipping methods |
| `GET` | `/products` | `?category=`, `?vendor=`; optional `X-Commerce-Store` |
| `GET` | `/products/{id\|slug}` | — |
| `GET` | `/products/{id}/variants` | — |
| `POST` | `/cart/guest` | `{ "currency": "AED" }` → returns `guest_token` |
| `GET` | `/cart/{id}` | `X-Guest-Cart-Token` |
| `POST` | `/cart/{id}/items` | `{ "variant_id", "quantity", "expected_version"? }` |
| `PATCH` | `/cart/{id}/items/{itemId}` | `{ "quantity" }` |
| `DELETE` | `/cart/{id}/items/{itemId}` | — |
| `POST` | `/cart/{id}/discount` | `{ "code" }` |
| `DELETE` | `/cart/{id}/discount` | `{ "code"? }` |
| `POST` | `/cart/{id}/calculate` | `{ "shipping_method", "price_list_id"? }` → returns `totals_hash` |
| `POST` | `/checkout` | `{ "cart_id", "shipping_method", "payment_method", "expected_totals_hash" }` + `X-Guest-Cart-Token` + `Idempotency-Key` |

### Protected routes (bearer token + scope)

Scopes: `catalog`, `inventory`, `orders`, `returns`, `customers`, `stores`, `companies`, `marketplace`, `subscriptions` — each with `.read` / `.write`. `COMMERCE_API_TOKEN` grants `*`. `.write` includes `.read` for the same prefix.

| Method | Path | Scope | Notes |
|--------|------|-------|-------|
| `POST` | `/cart/merge` | `customers.write` | Guest → customer cart |
| `POST` | `/customers/{id}/cart` | `customers.write` | Create or return active customer cart |
| `POST` | `/products` | `catalog.write` | Product + variant + price + optional stock |
| `GET/POST` | `/warehouses` | `inventory.read` / `inventory.write` | List / create warehouses |
| `GET` | `/warehouses/{id}` | `inventory.read` | Show warehouse |
| `GET` | `/warehouses/{id}/movements` | `inventory.read` | Stock movement audit |
| `POST` | `/warehouses/{id}/receive` | `inventory.write` | Receive stock |
| `POST` | `/warehouses/{id}/adjust` | `inventory.write` | Signed stock adjustment |
| `POST` | `/warehouses/{id}/transfer` | `inventory.write` | Transfer between warehouses |
| `POST` | `/warehouses/{id}/deactivate` | `inventory.write` | Deactivate warehouse |
| `POST` | `/reservations/{id}/release` | `inventory.write` | Release hold (numeric reservation id) |
| `GET/POST` | `/customer-groups` | `customers.read` / `customers.write` | Pricing groups |
| `GET` | `/orders/{id}/transitions` | `orders.read` | Status audit trail |
| `GET` | `/orders/{id}/fulfillments` | `orders.read` | Fulfillment history |
| `GET` | `/orders/{id}/refunds` | `orders.read` | Refund history |
| `GET` | `/orders/{id}/payments` | `orders.read` | Payment summary |
| `GET` | `/orders/{id}/payments/{paymentId}/transactions` | `orders.read` | Ledger transactions |
| `POST` | `/orders/{id}/cancel` | `orders.write` | Cancel unpaid order |
| `POST` | `/orders/{id}/complete` | `orders.write` | Mark order completed |
| `GET` | `/vendors/{id}/commissions` | `marketplace.read` | Commission list |
| `GET` | `/vendors/{id}/payouts` | `marketplace.read` | Payout history |
| `GET` | `/vendors/{id}/payouts/{payoutId}` | `marketplace.read` | Single payout + commissions |
| `GET/POST` | `/subscription-plans` | `subscriptions.read` / `subscriptions.write` | Plan admin |
| `GET` | `/inbound-webhooks/events` | `orders.read` | Processed gateway event log |
| `POST` | `/webhook-deliveries/{id}/retry` | `orders.write` | Retry failed outbound delivery (numeric id) |
| `POST` | `/vendors/{id}/payouts` | `marketplace.write` | Pay pending commissions |
| `GET` | `/orders/{id}` | `orders.read` | — |
| `POST` | `/orders/{id}/capture` | `orders.write` | Manual gateway capture |
| `POST` | `/orders/{id}/fulfill` | `orders.write` | `{ "order_item_id", "quantity" }` |
| `POST` | `/orders/{id}/refund` | `orders.write` | `{ "amount_minor", "reason"? }` |
| `POST` | `/orders/{id}/retry-payment` | `orders.write` | Re-create payment session |
| `POST` | `/orders/{id}/returns` | `orders.write` | Create return request |
| `GET` | `/returns`, `/returns/{id}` | `returns.read` | — |
| `POST` | `/returns/{id}/receive` | `returns.write` | Mark received |
| `POST` | `/returns/{id}/items/{itemId}/restock` | `returns.write` | Restock item |
| `POST` | `/returns/{id}/items/{itemId}/mark-damaged` | `returns.write` | Mark damaged |
| `GET/POST` | `/customers`, `/customers/{id}` | `customers.read` / `customers.write` | — |
| `GET/POST` | `/customers/{id}/addresses` | `customers.read` / `customers.write` | `country` → `country_code` |
| `GET/POST` | `/stores` | `stores.read` / `stores.write` | Multi-store |
| `GET/POST` | `/companies` | `companies.read` / `companies.write` | B2B |
| `GET/POST` | `/vendors` | `marketplace.read` / `marketplace.write` | Marketplace |
| `GET/POST` | `/subscriptions` | `subscriptions.read` / `subscriptions.write` | Subscriptions |

### Webhooks

| Method | Path | Auth |
|--------|------|------|
| `POST` | `/webhooks/stripe` | `Stripe-Signature` + `STRIPE_WEBHOOK_SECRET` |
| `POST` | `/webhooks/paypal` | Native verify when `PAYPAL_WEBHOOK_ID` set; else `X-Commerce-Webhook-Secret` |
| `POST` | `/webhooks/telr` | `X-Commerce-Webhook-Secret` |
| `POST` | `/webhooks/fake` | Local/testing only |

**Multi-store:** send `X-Commerce-Store` with store `public_id` or `slug` (enable `ez-ecommerce.features.multi_store`).

**Route IDs:** most resources use `public_id` (ULID) in URLs. Exceptions: cart line `itemId`, reservation id, webhook delivery id (numeric internal ids).

**Feature flags:** `subscriptions`, `marketplace`, `multi_store`, `b2b`, and `outbound_webhooks` default to `false`. Enable in config before using those modules.

**Production note:** run the `@group('hardening')` suite on MySQL in CI before treating checkout/payments as production-ready.

**Cart JSON shape:** cart `id` is the `public_id` (ULID), not the database integer.

---

## Payment drivers

Set default: `COMMERCE_PAYMENT_DRIVER=manual`

Per-checkout override: `->paymentMethod('stripe')` on CheckoutBuilder.

| Method | Captures automatically? | Inventory commit |
|--------|-------------------------|------------------|
| `manual` | No — pending until capture | After capture or policy |
| `null` | Only if total is 0 | Immediate if TTL is 0 |
| `fake` | Configurable in tests | Per policy |
| `net_terms` | No — B2B deferred | Per `reservation_ttl.net_terms` |
| `stripe` / `paypal` / `telr` | Gateway-dependent | Per policy |

---

## Feature flags

All default to `true` in `config/ez-ecommerce.php`. Set to `false` to disable gated code paths.

```php
'features' => [
    'api' => true,
    'subscriptions' => true,
    'marketplace' => true,
    'multi_store' => true,
    'b2b' => true,
    'outbound_webhooks' => true,
],
```

Disabling a flag does **not** skip migrations — tables still exist.

---

## Configuration reference

| Key | Purpose |
|-----|---------|
| `currency.default` | Default cart currency |
| `pricing.precedence` | Price resolver order |
| `pricing.tax_after_discounts` | Tax calculated after discounts |
| `pricing.shipping_taxable` | Include shipping in tax base |
| `drivers.payment.*` | Gateway credentials |
| `tax.rate` | Flat rate for `SimpleTaxCalculator` |
| `shipping.flat_rate_minor` | Flat shipping in minor units |
| `inventory.default_warehouse_id` | Default warehouse for allocation |
| `inventory.reservation_ttl.*` | Minutes to hold stock per payment method |
| `cart.guest_ttl_days` | Guest cart expiry |
| `multi_store.default_store_id` | Fallback store when no header |
| `api.token` / `api.allow_unauthenticated` | Legacy admin token / dev bypass |
| `api.scoped_tokens` | Per-token scope map (`COMMERCE_API_*_TOKEN` env vars) |
| `api.prefix` / `api.middleware` | REST API routing |
| `inbound_webhooks.shared_secret` / `allow_unsigned` | PayPal/Telr webhook auth |
| `outbound_webhooks.secret` | HMAC signing key |
| `outbound_webhooks.endpoints` | `[['url' => '...', 'events' => [...]]]` |
| `idempotency.ttl_minutes` | Idempotency record lifetime |
| `idempotency.lock_ttl_seconds` | Processing lock duration |

Publish config: `php artisan vendor:publish --tag=ez-ecommerce-config`

---

## Artisan commands & scheduler

```bash
php artisan commerce:install                      # Publish config + translations
php artisan commerce:release-expired-reservations # Release stale inventory holds
php artisan commerce:renew-subscriptions          # Renew periods + bill via BillSubscriptionPeriod
php artisan commerce:purge-expired-carts          # Delete expired guest carts
php artisan commerce:purge-idempotency-records    # Prune old idempotency rows
```

### Payment operator runbook

When a PSP call times out or returns an ambiguous error, the ledger records the attempt as `unknown` and blocks conflicting retries until an operator resolves it.

**Unknown captures** (`commerce:reconcile-payments`):

```bash
php artisan commerce:reconcile-payments --list
php artisan commerce:reconcile-payments {attempt} --retry
php artisan commerce:reconcile-payments {attempt} --mark-succeeded --amount=1000 --currency=AED --external-id=ch_xxx
php artisan commerce:reconcile-payments {attempt} --mark-failed
```

**Unknown refunds** (`commerce:reconcile-refunds`):

```bash
php artisan commerce:reconcile-refunds --list
php artisan commerce:reconcile-refunds {attempt} --retry
php artisan commerce:reconcile-refunds {attempt} --mark-succeeded --external-id=re_xxx
php artisan commerce:reconcile-refunds {attempt} --mark-failed
```

**Captured but not finalized** (`commerce:reconcile-finalizations`) — inventory commit or order confirmation failed after capture:

```bash
php artisan commerce:reconcile-finalizations --list
php artisan commerce:reconcile-finalizations {payment} --complete
```

`{attempt}` and `{payment}` are numeric internal IDs from the `--list` output.

**Recommended scheduler** (in your app's `routes/console.php`):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('commerce:release-expired-reservations')->everyFiveMinutes();
Schedule::command('commerce:renew-subscriptions')->hourly();
Schedule::command('commerce:purge-expired-carts')->daily();
Schedule::command('commerce:purge-idempotency-records')->daily();
```

---

## Extending the engine

### Swappable contracts

| Interface | Default | Register in |
|-----------|---------|-------------|
| `PriceResolver` | `DefaultPriceResolver` | `PricingServiceProvider` |
| `TaxCalculator` | `SimpleTaxCalculator` | `TaxesServiceProvider` |
| `ShippingCalculator` | `FlatShippingCalculator` | `ShippingServiceProvider` |
| `PaymentGateway` | Per `drivers.payment.default` | `PaymentsServiceProvider` |
| `CustomerResolver` | `DefaultCustomerResolver` | `CustomersServiceProvider` |
| `ReservationPolicy` | `ConfigReservationPolicy` | `InventoryServiceProvider` |
| `StoreContext` | `DefaultStoreContext` | `StoresServiceProvider` |

### Morph map for host models

```php
// AppServiceProvider
use EzEcommerce\Facades\EzEcommerce;

EzEcommerce::morphMap([
    'my_user' => \App\Models\User::class,
]);
```

### Outbound webhooks

```php
// config/ez-ecommerce.php
'outbound_webhooks' => [
    'secret' => env('COMMERCE_WEBHOOK_SECRET'),
    'endpoints' => [
        [
            'url' => 'https://your-app.com/webhooks/commerce',
            'events' => ['order.placed', 'order.paid'],
        ],
    ],
],
```

---

## Testing

```bash
composer test
# or
vendor/bin/pest
vendor/bin/pest --group=hardening   # MySQL concurrency / payment correctness suite
vendor/bin/pint --dirty
vendor/bin/phpstan analyse
```

---

## Test coverage honesty

**97 tests. 18 files.** Core commerce, API, security, schema/relations, backlog implementation, payment hardening.

| Test file | Coverage |
|-----------|----------|
| `CommerceFlowTest` | Boot, features, cart→checkout, idempotency, money |
| `HardeningTest` | Capture, fulfill, refund, idempotency replay, OOS, null gateway |
| `CorrectnessHardeningTest` | Unknown captures/refunds, `OrderPaid` transitions, provider-confirmed ledger, retry snapshots |
| `ApiTest` | Products, guest cart, checkout validation |
| `SecurityTest` | Fail-closed API, scopes, checkout token, Stripe/PayPal webhook auth |
| `ApiExtendedTest` | API token auth, order capture/fulfill, discount CRUD, stores/companies/vendors/subscriptions |
| `SprintApiTest` | Customers, addresses, cart merge, retry payment, returns API |
| `BacklogApiTest` | Customer cart, catalog write, inventory admin, payouts, scoped tokens |
| `BacklogImplementationTest` | Order cancel, marketplace reads, plans, groups, categories, cart expiry, webhooks |
| `SchemaAndRelationsTest` | Migrations, FK integrity, model relations |
| `ModulesTest` | Discounts, pricing, subscriptions, returns, money allocate |
| `ModulesExtendedTest` | Outbox webhooks, inbound webhooks, fake gateway, renew+bill, marketplace commission, reservations |

---

## Sprint backlog (next up)

1. **Catalog update/delete API**
2. **Automated bank/PSP payout transfers** (payout API records paid commissions today)
3. **Customer-authenticated cart routes** (host user → customer, not only admin token)

See [`AGENTS.md`](AGENTS.md) for agent-specific rules when implementing these.

---

## License

The MIT License (MIT)

Copyright (c) Nawras Al Bukhari nawrasalbukhari@gmail.com

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
