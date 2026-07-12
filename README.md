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
COMMERCE_DEFAULT_STORE_ID=
COMMERCE_WEBHOOK_SECRET=
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
| Discount codes | `applyDiscount` on CartManager / API | Percent and fixed types |
| Checkout | `checkout()->for($cart)->place()` | Idempotent; returns `CheckoutResult` |
| Orders | Snapshots on line items | Immutable product data at purchase time |
| Inventory | Reserve → commit on payment | Signed movements; race-safe expiry release |
| Manual capture | `CapturePayment` action / API | After `manual` gateway pending session |
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
| `stripe` | Partial | Sessions; optional SDK; verify webhooks yourself |
| `paypal` | Partial | HTTP integration; no webhook verification |
| `telr` | Partial | Order creation; capture/refund incomplete |

### REST API (`api/ez-commerce/v1`)

Enabled when `features.api` is `true` (default).

| Endpoint | Works |
|----------|-------|
| `GET /products`, `GET /products/{id}`, `GET /products/{id}/variants` | Yes |
| `POST /cart/guest` | Yes — returns `guest_token` |
| Cart CRUD + calculate + discount | Yes — requires `X-Guest-Cart-Token` |
| `POST /checkout` | Yes — requires `Idempotency-Key` |
| `GET /orders/{id}`, capture, fulfill, refund | Yes — **no auth middleware** (you must add it) |

### Optional modules (scaffolding + real actions)

| Module | What works | Limit |
|--------|------------|-------|
| **Subscriptions** | `CreateSubscription`, `RenewSubscription`, `commerce:renew-subscriptions` | Period dates only — **no billing charge** |
| **Marketplace** | `RecordVendorCommissions` on order create | No vendor API or payouts |
| **Multi-store** | `StoreContext`, `store_id` on cart/order, `X-Commerce-Store` header | No store management API |
| **B2B** | `Company` model, `net_terms` payment, terms metadata on order | No company CRUD API |
| **Outbound webhooks** | Signed POST on `OrderPlaced` / `OrderPaid` | Config URL list only; DB endpoint table unused |

### Artisan commands

```bash
php artisan commerce:install
php artisan commerce:release-expired-reservations
php artisan commerce:renew-subscriptions
```

---

## What you CANNOT expect yet

Be honest with your sprint planning. These are **not** production-complete:

### No built-in UI

This is a **headless engine**. No storefront, admin panel, product editor, or checkout page. You build those in your app.

### Security gaps you must close in the host app

- Order API endpoints (`/orders/*`) have **no authentication** — add middleware before production.
- Inbound PSP webhooks have **models and `ReconcilePayment` action** but **no routes**.
- Stripe webhook **signature verification is not implemented**.
- Guest cart auth is token-only (`X-Guest-Cart-Token`); customer cart auth assumes `user->commerce_customer_id` on your User model.

### Payment / webhook gaps

| Item | Status |
|------|--------|
| `ReconcilePayment` | Action exists, **no HTTP entry point** |
| `RetryPaymentSession` | Action exists, **not exposed** |
| Telr `capture()` / `refund()` | Incomplete |
| `drivers.payment.stripe.webhook_secret` | Config exists, **unused in code** |

### API gaps

No REST routes for: customers, addresses, subscriptions, vendors, companies, stores, returns, inventory admin, inbound webhooks, cart merge.

### Feature scaffolding vs product

| Advertised | Reality |
|------------|---------|
| Subscriptions | No Stripe Billing, invoices, or dunning |
| Marketplace | Commission rows only |
| B2B | Metadata on orders; no credit limits or approval flows |
| Multi-store | Column + header; no catalog isolation enforcement everywhere |
| Outbound webhooks | Fire-and-forget job; no delivery persistence/retry UI |
| `OutboxMessage` table | Migration exists; **no writer/consumer** |

### Config keys that do nothing today

- `currency.rounding` — defined, never read
- `drivers.shipping.default` — hard-bound to `FlatShippingCalculator`
- `drivers.tax.default` — hard-bound to `SimpleTaxCalculator`

### Duplicate / unwired code

- Two `ApplyDiscountCode` classes: `Cart\Actions\` (used by manager/API) vs `Discounts\Actions\` (has date validation, **not used by manager**)
- `RemoveDiscountCode` — exists, **not on CartManager or API**
- `MarkReturnedItemAsDamaged` — exists, **no caller**
- `OrderManager` (fulfill) is **not** on the `EzEcommerce` facade — inject it or use API

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
| Products | Public |
| `POST /cart/guest` | Public |
| Cart mutations | `X-Guest-Cart-Token` header |
| Checkout | `Idempotency-Key` header (required) |
| Orders | **None built-in — add your own** |

### Endpoints

| Method | Path | Body / headers |
|--------|------|----------------|
| `GET` | `/products` | — |
| `GET` | `/products/{id\|slug}` | — |
| `GET` | `/products/{id}/variants` | — |
| `POST` | `/cart/guest` | `{ "currency": "AED" }` |
| `GET` | `/cart/{id}` | `X-Guest-Cart-Token` |
| `POST` | `/cart/{id}/items` | `{ "variant_id", "quantity", "expected_version"? }` |
| `PATCH` | `/cart/{id}/items/{itemId}` | `{ "quantity" }` |
| `DELETE` | `/cart/{id}/items/{itemId}` | — |
| `POST` | `/cart/{id}/discount` | `{ "code" }` |
| `POST` | `/cart/{id}/calculate` | `{ "shipping_method" }` |
| `POST` | `/checkout` | `{ "cart_id", "shipping_method", "payment_method" }` + headers |
| `GET` | `/orders/{id}` | — |
| `POST` | `/orders/{id}/capture` | — |
| `POST` | `/orders/{id}/fulfill` | `{ "order_item_id", "quantity" }` |
| `POST` | `/orders/{id}/refund` | `{ "amount_minor" }` |

**Multi-store:** send `X-Commerce-Store` with store `public_id` or `slug`.

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
| `api.prefix` / `api.middleware` | REST API routing |
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
php artisan commerce:renew-subscriptions          # Roll subscription periods (no charge)
```

**Recommended scheduler** (in your app's `routes/console.php`):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('commerce:release-expired-reservations')->everyFiveMinutes();
Schedule::command('commerce:renew-subscriptions')->hourly();
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
vendor/bin/pint --dirty
vendor/bin/phpstan analyse
```

---

## Test coverage honesty

**17 tests. 4 files. Happy paths only.** Do not assume full module coverage.

| Test file | What it proves | What it does NOT test |
|-----------|----------------|----------------------|
| `CommerceFlowTest` | Package boots; features on; cart→checkout (manual); idempotency mismatch; Money | Catalog/inventory managers; other gateways; store/B2B |
| `HardeningTest` | Capture→fulfill→partial refund; idempotency replay; OOS rejection; null gateway | Stripe/PayPal/Telr; full refund; reservation command |
| `ApiTest` | Products list; guest cart create; checkout 422 without idempotency key | 12 other endpoints; successful HTTP checkout; auth |
| `ModulesTest` | % discount; customer price precedence; create subscription; return+restock; Money::allocate | Renew command; marketplace; B2B net_terms; damaged returns; webhooks |

### Modules with zero dedicated tests

- Payment drivers: Stripe, PayPal, Telr, Fake (except null rejection)
- Webhooks: outbound dispatch, inbound reconcile
- API: capture, fulfill, refund, cart CRUD, successful checkout
- Cart: merge, version conflicts, remove/update items
- Discounts: fixed amount, expired codes, remove discount
- Pricing: customer_group, price_list, sale precedence
- Inventory: `commerce:release-expired-reservations`, receive via manager
- Subscriptions: `commerce:renew-subscriptions`
- Marketplace: vendor commissions
- Multi-store / B2B: store header, company terms
- Commands: `commerce:install`
- Middleware: `GuestCartToken` rejection paths

**Verdict:** Tests prove the **core transactional spine** works. They do **not** prove every module or API endpoint is correct. Add tests before shipping a feature to production.

---

## Sprint backlog (not built yet)

Priority gaps if you're continuing development:

1. **API authentication** on order endpoints
2. **Inbound webhook routes** + Stripe signature verification
3. **Unify `ApplyDiscountCode`** (date validation in cart path)
4. **Expose `RemoveDiscountCode`** on CartManager + API
5. **Subscription billing** (actual charge, not just period dates)
6. **Marketplace vendor API** and payout flow
7. **Store / company CRUD** APIs
8. **Shipping & tax driver resolvers** (honor `drivers.shipping.default`)
9. **Expand Pest suite** — at minimum one test per API endpoint and payment driver
10. **Outbox pattern** — wire `OutboxMessage` or remove migration

See [`AGENTS.md`](AGENTS.md) for agent-specific rules when implementing these.

---

## License

MIT
