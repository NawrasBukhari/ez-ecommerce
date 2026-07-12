# ez-ecommerce

A modular, headless e-commerce engine for Laravel.

*No storefront included. That's a feature. Your designer can argue with CSS; this package argues with inventory.*

## Origin story

This package was **vibe-coded by one engineer** — me — with architectural peer review from two friends who technically do not exist on payroll: **ChatGPT 5.5 High** and **Claude Opus 4.8**.

How it went:

1. **Me:** “I need a headless commerce engine, not a tutorial cart.”
2. **ChatGPT:** “Have you considered idempotency keys, signed inventory deltas, and a payment aggregate?”
3. **Opus:** “Have you considered sleep? You won’t get any.”
4. **Me:** “What if we capture payment inside the DB transaction?”
5. **Both:** *audible markdown screaming*
6. **Three hours later:** 50 migrations, a `CheckoutResult` enum, and a man who trusts `vendor/bin/pest` more than his own memory.

There was no steering committee. No six-month discovery phase. No Figma file blessed by twelve stakeholders. Just vibes, `brick/money`, and a sacred rule that **`Purchasable` does not get a `price()` method** — because once you add convenience methods, entropy wins and the cart never checks out again.

The AIs argued about webhook semantics at 2 AM, suggested “one more abstraction,” and never confessed to invading my privacy. I appreciate that. Or they’re just polite liars. Either way, we’re good.

What you’re holding is the result: a real Laravel package with orders, inventory, payments, refunds, returns, and a versioned REST API — not a demo that dies the moment you add tax.

**For AI agents (Cursor, Copilot, Claude Code, etc.):** read [`AGENTS.md`](AGENTS.md) first. Your job is to extend the vibes, not un-vibe the architecture.

---

## Table of contents

- [Origin story](#origin-story)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start (PHP)](#quick-start-php)
- [Quick start (REST API)](#quick-start-rest-api)
- [Production readiness](#production-readiness)
- [What works today](#what-works-today)
- [What is not ready](#what-is-not-ready)
- [Architecture](#architecture)
- [Facade & managers](#facade--managers)
- [REST API](#rest-api)
- [Payment drivers](#payment-drivers)
- [Operator runbook](#operator-runbook)
- [Feature flags](#feature-flags)
- [Configuration](#configuration)
- [Artisan commands & scheduler](#artisan-commands--scheduler)
- [Extending the engine](#extending-the-engine)
- [Testing & CI](#testing--ci)
- [Roadmap](#roadmap)
- [License](#license)

---

## Requirements

You need PHP, Laravel, and a database. Shocking, we know.

| Requirement | Version |
|-------------|---------|
| PHP | 8.2+ |
| Laravel | 11, 12, or 13 |
| Database | MySQL, PostgreSQL, or SQLite |

Optional payment SDKs (install only if you enjoy talking to banks):

```powershell
composer require stripe/stripe-php
composer require paypal/paypal-checkout-sdk
```

---

## Installation

Three commands. If this breaks, it's either Composer, the universe, or that one `.env` typo you swear isn't there.

```powershell
composer require ez-ecommerce/ez-ecommerce
php artisan commerce:install
php artisan migrate
```

- `commerce:install` publishes **config** and **translations**.
- Migrations load from the package automatically. There is **no** `commerce:migrate` — the host app runs `php artisan migrate` like any other package. We resisted the urge to invent a fourth artisan command. You're welcome.

Copy variables from [`.env.example`](.env.example). Minimum for local dev (do not ship `manual` + `ALLOW_UNAUTHENTICATED=true` to prod unless your risk appetite is… adventurous):

```dotenv
COMMERCE_CURRENCY=AED
COMMERCE_TAX_RATE=0.05
COMMERCE_SHIPPING_FLAT_MINOR=1000
COMMERCE_PAYMENT_DRIVER=manual
COMMERCE_API_TOKEN=your-strong-token
COMMERCE_API_ALLOW_UNAUTHENTICATED=false
```

See [Configuration](#configuration) for Stripe, PayPal, Telr, scoped API tokens, and webhook secrets.

---

## Quick start (PHP)

From zero to order in one screenful. Use a **stable** idempotency key in prod — `uniqid()` is for demos, not for the checkout button your CEO double-clicks.

```php
use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Customers\Data\CustomerIdentity;

['cart' => $cart, 'guest_token' => $token] = EzEcommerce::cart()->createGuest('AED');

EzEcommerce::cart()->addItem($cart, $variant, 2);
$cart = EzEcommerce::cart()->calculateTotals($cart, shippingMethod: 'flat');

$result = EzEcommerce::checkout()->for($cart)
    ->shippingMethod('flat')
    ->paymentMethod('manual')
    ->customerIdentity(new CustomerIdentity(email: 'buyer@example.com'))
    ->place(
        idempotencyKey: 'checkout-'.uniqid(),
        expectedTotalsHash: EzEcommerce::cart()->totalsHash($cart, 'flat'),
    );

$order   = $result->order;
$payment = $result->payment;
$status  = $result->status; // CheckoutStatus enum
```

For `manual` payments, capture after fulfillment review via `CapturePayment` or `POST /orders/{id}/capture`. Yes, you have to *decide* to take the money. Revolutionary.

---

## Quick start (REST API)

Base path: `/api/ez-commerce/v1` (configurable). Lose the `X-Guest-Cart-Token` and the cart ghosts you. Literally — 403.

```http
POST /api/ez-commerce/v1/cart/guest
{ "currency": "AED" }
→ { "data": { "id": "<cart-public-id>", "guest_token": "..." } }

POST /api/ez-commerce/v1/cart/{id}/items
X-Guest-Cart-Token: <guest_token>
{ "variant_id": "<variant-public-id>", "quantity": 2 }

POST /api/ez-commerce/v1/cart/{id}/calculate
X-Guest-Cart-Token: <guest_token>
{ "shipping_method": "flat" }
→ totals_hash in response

POST /api/ez-commerce/v1/checkout
X-Guest-Cart-Token: <guest_token>
Idempotency-Key: <stable-key>
{
  "cart_id": "<cart-public-id>",
  "shipping_method": "flat",
  "payment_method": "manual",
  "expected_totals_hash": "<from calculate>",
  "customer": {
    "email": "buyer@example.com",
    "first_name": "Ada",
    "last_name": "Lovelace",
    "phone": "+971500000000"
  },
  "shipping_address": {
    "line1": "1 Sheikh Zayed Rd",
    "city": "Dubai",
    "country_code": "AE"
  },
  "billing_address": { "...": "..." }
}
```

Guest cart auth is checked **before** any cart mutation (including optional `price_list_id` on checkout). We learned this the hard way so you don't have to.

---

## Production readiness

Honest tiers as of the latest hardening sprint. *"Prototype"* means "works in tests and my Stripe sandbox"; *"Strong beta"* means "I'd deploy it before lunch, but I'd eat lunch first."

| Tier | Status | Notes |
|------|--------|-------|
| **Manual / null checkout** | Strong beta | Idempotent checkout, inventory reservations, capture, fulfill, refund, operator reconciliation |
| **Headless REST API** | Beta | Full cart/checkout/order surface; scoped tokens; guest + admin auth |
| **Stripe** | Integration prototype | Manual capture PaymentIntents; webhook ledger; idempotency keys — verify in your Stripe account before live money |
| **PayPal** | Integration prototype | Order capture flow; native webhook verify when `PAYPAL_WEBHOOK_ID` set; approval ≠ capture |
| **Telr** | Sessions + verified refunds only | No server-side capture; payment success only from verified Telr callback/webhook |

Before treating payments as production-ready:

1. Run `vendor/bin/pest --group=hardening` on **MySQL** (CI does this).
2. Configure real webhook secrets and API tokens (never `COMMERCE_API_ALLOW_UNAUTHENTICATED=true` in prod).
3. Exercise operator commands for your PSP (`commerce:reconcile-*`). Think of them as commerce therapy for when the network blinks mid-capture.

---

## What works today

The boring stuff that actually ships boxes (or digital goods, we're not judging).

### Core commerce

| Area | Entry point | Notes |
|------|-------------|-------|
| Guest carts | `EzEcommerce::cart()->createGuest()` | Returns `guest_token` for API |
| Cart CRUD | `addItem`, `updateItem`, `removeItem` | Optimistic `expected_version` |
| Totals | `calculateTotals`, `totalsHash` | Flat/weight shipping, simple or jurisdiction tax |
| Discounts | `applyDiscount` / `removeDiscount` | Percent/fixed codes |
| Checkout | `checkout()->for($cart)->place()` | Idempotent `CheckoutResult`; payment session outside DB txn |
| Orders | `CreateOrderFromCart` | Line snapshots; address snapshots on order |
| Inventory | Reserve → commit on capture | Signed movements; expiry release command |
| Capture | `CapturePayment` / API | Dedicated capture attempts per request |
| Fulfillment | `OrderManager::fulfill()` / API | Requires **full** capture for PSP payments |
| Refunds | `RefundPayment` / API | Separate from returns; idempotency payload checks |
| Returns | create → receive → restock | Physical return workflow |
| Reconciliation | `commerce:reconcile-*` | Unknown PSP attempts; post-capture finalization recovery |

### Pricing

- `DefaultPriceResolver`: **customer → customer_group → price_list → sale → base**
- All money in **integer minor units** (`brick/money`) — floats are for latitude, not ledgers
- `Purchasable` has no `price()` — always resolve via `PriceResolver`. We will die on this hill. Politely.

### Optional modules (enable in config)

| Module | Capabilities | Default flag |
|--------|--------------|--------------|
| Subscriptions | Plans API, renew + bill command | `features.subscriptions` = `false` |
| Marketplace | Vendors, commissions, payout API | `features.marketplace` = `false` |
| Multi-store | `StoreContext`, `X-Commerce-Store` header | `features.multi_store` = `false` |
| B2B | Companies, `net_terms`, payment terms on order | `features.b2b` = `false` |
| Outbound webhooks | Outbox + signed delivery jobs | `features.outbound_webhooks` = `false` |
| Inbound webhooks | `POST /webhooks/{gateway}` | Always registered when API enabled |

`features.api` defaults to `true`. Disabling a flag does not skip migrations — the tables exist whether you use them or not, like gym membership.

---

## What is not ready

The honest "please don't `@` me on GitHub about this" list:

- **Storefront / admin UI** — headless only; you build the front end. We sell engines, not paint jobs.
- **Catalog update/delete API** — create + read only.
- **Refund webhook reconciliation** — async PSP refund events not fully wired.
- **Outbound webhook delivery guarantees** — host must run queue workers; retries need hardening.
- **Automated bank/PSP payouts** — payout API records commissions as paid.
- **PostgreSQL CI job** — supported by schema, not in CI matrix yet.
- **Multi-process concurrency tests** — sequential Pest only.
- **`currency.rounding` config** — defined, unused. Schrödinger's setting.

`OrderManager::fulfill()` is **not** on the `EzEcommerce` facade — use DI or the API. We hid fulfill on purpose so you wouldn't one-line your way into shipping unpaid orders. You're welcome. Again.

---

## Architecture

```
Your App (API/UI)
       │
       ▼
 EzEcommerce Facade
       │
       ├── CartManager ──► calculateTotals / discounts
       ├── CheckoutManager ──► PlaceOrder
       │         │
       │         ├── DB transaction: order + reserve inventory
       │         └── OUTSIDE txn: CreatePaymentSession (PSP)
       ├── InventoryManager ──► receive / release reservations
       └── OrdersManager ──► lookup / recalc status
```

### Design rules

*Break these and the tests will find you. Eventually. Maybe at 2 AM.*

1. **Payments never run inside DB transactions.** (Rule #1 exists because someone — not naming names — almost did.)
2. **Refunds ≠ returns ≠ restock** — three separate workflows. Conflating them is how you refund a sofa and restock a feeling.
3. **Checkout is idempotent** — stable `Idempotency-Key` / `idempotencyKey`.
4. **Money is integers** — minor units only.
5. **Morph aliases** (`commerce_product_variant`) — not FQCNs in polymorphic columns.
6. **Unknown PSP attempts block conflicts** until operator reconciliation.
7. **Webhook inbox ID ≠ ledger transaction ID** — separate `eventId`, `paymentReference`, `transactionReference`.

---

## Facade & managers

Your cheat sheet. `orders()` looks up orders; it does not fulfill them. Fulfillment is a separate ritual.

```php
EzEcommerce::cart()       // CartManager
EzEcommerce::checkout()   // CheckoutManager → CheckoutBuilder
EzEcommerce::catalog()    // CatalogManager
EzEcommerce::inventory()  // InventoryManager
EzEcommerce::orders()     // OrdersManager (lookup/recalc — not fulfill)
EzEcommerce::morphMap([...])
```

### CheckoutBuilder

```php
EzEcommerce::checkout()->for($cart)
    ->customerIdentity($identity)
    ->shippingAddress($address)   // persisted or inline Address model
    ->billingAddress($address)
    ->shippingMethod('flat')
    ->paymentMethod('stripe')
    ->place($idempotencyKey, $expectedTotalsHash);
```

---

## REST API

**Prefix:** `api/ez-commerce/v1`

*Headless means you bring the HTML. We bring the HTTP that doesn't embarrass you in production.*

### Authentication

| Routes | Auth |
|--------|------|
| Products, categories, shipping methods, `POST /cart/guest` | Public |
| Cart mutations | `X-Guest-Cart-Token` |
| `POST /checkout` | Guest token + `Idempotency-Key` |
| Orders, inventory, customers, admin | `Authorization: Bearer {token}` or `X-Commerce-Api-Token` |
| Inbound webhooks | Per-gateway (see below) |

Forgot the token? The API says no. This is commerce, not a house party.

**Scopes:** `catalog`, `inventory`, `orders`, `returns`, `customers`, `stores`, `companies`, `marketplace`, `subscriptions` — each `.read` / `.write`. `COMMERCE_API_TOKEN` grants `*`. With great power comes great `git blame`.

### Guest & checkout routes

| Method | Path | Notes |
|--------|------|-------|
| `POST` | `/cart/guest` | `{ "currency": "AED" }` |
| `GET` | `/cart/{id}` | Guest token required |
| `POST/PATCH/DELETE` | `/cart/{id}/items[...]` | `variant_id`, `quantity`, optional `expected_version` |
| `POST/DELETE` | `/cart/{id}/discount` | Apply/remove promo |
| `POST` | `/cart/{id}/calculate` | `{ "shipping_method", "price_list_id"? }` → `totals_hash` |
| `POST` | `/checkout` | See [Quick start (REST API)](#quick-start-rest-api); optional `price_list_id` |

### Order & admin routes (token required)

| Method | Path | Scope |
|--------|------|-------|
| `GET` | `/orders/{id}` | `orders.read` |
| `POST` | `/orders/{id}/capture` | `orders.write` |
| `POST` | `/orders/{id}/fulfill` | `orders.write` |
| `POST` | `/orders/{id}/refund` | `orders.write` |
| `POST` | `/orders/{id}/retry-payment` | `orders.write` |
| `POST` | `/orders/{id}/cancel` | `orders.write` |
| `POST` | `/orders/{id}/complete` | `orders.write` |
| `GET` | `/orders/{id}/transitions`, `/fulfillments`, `/refunds`, `/payments` | `orders.read` |
| `POST` | `/orders/{id}/returns` | `orders.write` |
| `GET/POST` | `/returns[...]` | `returns.read` / `returns.write` |
| `GET/POST` | `/customers`, `/customers/{id}/addresses` | `customers.*` |
| `POST` | `/cart/merge` | `customers.write` |
| `GET/POST` | `/warehouses[...]`, `/reservations/{id}/release` | `inventory.*` |
| `POST` | `/products` | `catalog.write` |
| `GET` | `/inbound-webhooks/events` | `orders.read` |

Routes for stores, companies, vendors, and subscriptions register only when their feature flag is enabled.

### Inbound webhooks

| Gateway | Path | Verification |
|---------|------|--------------|
| Stripe | `POST /api/ez-commerce/v1/webhooks/stripe` | `Stripe-Signature` + `STRIPE_WEBHOOK_SECRET` |
| PayPal | `POST .../webhooks/paypal` | Native when `PAYPAL_WEBHOOK_ID` set; else `X-Commerce-Webhook-Secret` |
| Telr | `POST .../webhooks/telr` | `X-Commerce-Webhook-Secret` |
| fake | `POST .../webhooks/fake` | Local/testing only — the gateway equivalent of "trust me bro" |

**IDs in URLs:** resource `public_id` (ULID) unless noted (cart line `itemId`, reservation id, webhook delivery id are numeric).

**Multi-store:** `X-Commerce-Store` header with store `public_id` or `slug`.

---

## Payment drivers

Default: `COMMERCE_PAYMENT_DRIVER=manual`. Override per checkout with `->paymentMethod('stripe')`. Pick your fighter.

| Driver | Capture | Refund | Webhooks | Production notes |
|--------|---------|--------|----------|------------------|
| `manual` | API / action | Yes | No | Default for B2B and admin capture |
| `null` | Auto on zero total | No | No | Free orders only |
| `fake` | Test double | Test double | Yes | Testing only — do not point this at your CFO |
| `net_terms` | Deferred (manual gateway) | Yes | No | B2B; terms on order metadata |
| `stripe` | Manual PI + capture endpoint | Yes | Yes | `capture_method=manual`; idempotency on session/capture/refund |
| `paypal` | Server capture of approved order | Yes | Yes | Does not treat approval as capture; order ID for lookup |
| `telr` | **Not supported** | Verified HTTP | Yes | Session redirect; success from callback only — capture is not a vibe Telr supports |

### Capture → inventory flow

*Money moves, stock moves, events fire once. If step 4 happens, make coffee and open the runbook.*

1. Checkout creates order + reservation + payment session (outside txn).
2. Capture records ledger transaction with provider `transactionReference`.
3. On **full** capture: commit reservation, confirm order, dispatch `OrderPaid` (once).
4. If inventory commit fails after capture: `manual_review_required` on order; use `commerce:reconcile-finalizations`.

Partial capture updates payment status but does **not** release goods (fulfillment requires full `Captured` for PSP payments).

---

## Operator runbook

When a PSP call times out, the attempt is stored as `unknown` and blocks conflicting operations until resolved. The payment is neither succeeded nor failed — it's in quantum superposition until you collapse the wavefunction with `--mark-succeeded` or `--mark-failed`.

### Unknown captures

```powershell
php artisan commerce:reconcile-payments --list
php artisan commerce:reconcile-payments {attempt} --retry
php artisan commerce:reconcile-payments {attempt} --mark-succeeded --amount=1000 --currency=AED --external-id=ch_xxx
php artisan commerce:reconcile-payments {attempt} --mark-failed
```

### Unknown refunds

```powershell
php artisan commerce:reconcile-refunds --list
php artisan commerce:reconcile-refunds {attempt} --retry
php artisan commerce:reconcile-refunds {attempt} --mark-succeeded --external-id=re_xxx
php artisan commerce:reconcile-refunds {attempt} --mark-failed
```

### Captured but not finalized

```powershell
php artisan commerce:reconcile-finalizations --list
php artisan commerce:reconcile-finalizations {payment} --complete
```

`{attempt}` and `{payment}` are numeric internal IDs from `--list`.

---

## Feature flags

Most modules ship **off** by default. Opt in to the chaos you actually need.

Published defaults in `config/ez-ecommerce.php`:

```php
'features' => [
    'api' => true,
    'subscriptions' => false,
    'marketplace' => false,
    'multi_store' => false,
    'b2b' => false,
    'outbound_webhooks' => false,
],
```

Enable modules your host app needs, then run `php artisan config:clear`.

---

## Configuration

| Key | Purpose |
|-----|---------|
| `currency.default` | Default cart currency |
| `pricing.precedence` | Price resolver order |
| `pricing.tax_after_discounts` | Tax after discounts |
| `pricing.shipping_taxable` | Tax shipping in base |
| `drivers.payment.*` | Gateway classes + credentials |
| `tax.rate` / `tax.jurisdictions` | `SimpleTaxCalculator` / `JurisdictionTaxCalculator` |
| `shipping.flat_rate_minor` / `shipping.weight` | Flat and weight-based shipping |
| `inventory.reservation_ttl.*` | Hold minutes per payment method |
| `cart.guest_ttl_days` | Guest cart expiry |
| `api.token` / `api.scoped_tokens` | Admin and scoped API tokens |
| `api.allow_unauthenticated` | Dev-only bypass (`false` in prod) — `true` is "yolo mode" |
| `inbound_webhooks.shared_secret` | PayPal/Telr fallback auth |
| `outbound_webhooks.secret` / `endpoints` | HMAC + event subscriptions |
| `idempotency.ttl_minutes` / `lock_ttl_seconds` | Checkout idempotency lifetime |

Publish: `php artisan vendor:publish --tag=ez-ecommerce-config`

---

## Artisan commands & scheduler

Housekeeping commands. Run them on a schedule or live dangerously — expired reservations don't release themselves (yet).

```powershell
php artisan commerce:install
php artisan commerce:release-expired-reservations
php artisan commerce:renew-subscriptions
php artisan commerce:purge-expired-carts
php artisan commerce:purge-idempotency-records
php artisan commerce:reconcile-payments --list
php artisan commerce:reconcile-refunds --list
php artisan commerce:reconcile-finalizations --list
```

Recommended scheduler in the host app:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('commerce:release-expired-reservations')->everyFiveMinutes();
Schedule::command('commerce:renew-subscriptions')->hourly();
Schedule::command('commerce:purge-expired-carts')->daily();
Schedule::command('commerce:purge-idempotency-records')->daily();
```

---

## Extending the engine

Swap contracts, don't fork the universe. If you're copy-pasting `PlaceOrder`, you've gone too far.

| Contract | Default |
|----------|---------|
| `PriceResolver` | `DefaultPriceResolver` |
| `TaxCalculator` | `SimpleTaxCalculator` (or `JurisdictionTaxCalculator`) |
| `ShippingCalculator` | `FlatShippingCalculator` / `WeightShippingCalculator` |
| `PaymentGateway` | Per `drivers.payment.default` |
| `CustomerResolver` | `DefaultCustomerResolver` |
| `ReservationPolicy` | `ConfigReservationPolicy` |
| `FulfillmentReleasePolicy` | `DefaultFulfillmentReleasePolicy` |
| `StoreContext` | `DefaultStoreContext` |

Register host morph aliases (so polymorphic relations don't store FQCNs longer than your commit messages):

```php
EzEcommerce::morphMap(['my_user' => \App\Models\User::class]);
```

Outbound webhooks example:

```php
'outbound_webhooks' => [
    'secret' => env('COMMERCE_WEBHOOK_SECRET'),
    'endpoints' => [
        ['url' => 'https://your-app.com/hooks/commerce', 'events' => ['order.placed', 'order.paid']],
    ],
],
```

---

## Testing & CI

**The vibe-coded release checklist:**

| Result | What to do |
|--------|------------|
| All 97 green | You're ready to go. Ship it. Tell nobody you only ran the tests once. |
| One red | Panic. Then fix it. Then panic again because you almost shipped. |
| All red | Close the laptop. The engine is telling you something. Probably "sleep." |
| PHPStan green, Pest red | Your types are lying. Trust the tests. |
| Pest green, PHPStan red | Your types are honest. Your runtime is a miracle. Fix PHPStan anyway. |

```powershell
composer test
vendor/bin/pest --group=hardening   # payment correctness on MySQL
vendor/bin/pint --dirty
vendor/bin/phpstan analyse --memory-limit=512M
```

### CI (GitHub Actions)

| Job | What it runs |
|-----|----------------|
| **SQLite matrix** | PHP 8.2–8.4 × Laravel 11/12/13; `pest` + PHPStan (`--prefer-lowest`) |
| **Hardening (MySQL)** | `pest --group=hardening` on MySQL 8 |

Package dev uses `testbench.yaml` so PHPStan boots without Orchestra Canvas conflicts. PHPStan and Canvas have… history.

### Test suite (97 tests, 13 files)

*97 tests can't prove your checkout works in prod, but 97 failures can prove it doesn't work in CI. Start there.*

| File | Focus |
|------|-------|
| `CommerceFlowTest` | Boot, cart→checkout, idempotency, money |
| `HardeningTest` | Manual capture, fulfill, refund, OOS |
| `CorrectnessHardeningTest` | Unknown captures/refunds, `OrderPaid`, reconciliation |
| `ApiTest` / `ApiExtendedTest` | REST surface, token auth, capture/fulfill |
| `SecurityTest` | Fail-closed API, scopes, webhook auth |
| `SprintApiTest` | Customers, cart merge, retry payment, returns |
| `BacklogApiTest` / `BacklogImplementationTest` | Admin APIs, categories, webhooks, cart expiry |
| `SchemaAndRelationsTest` | Migrations (50), FK integrity |
| `ModulesTest` / `ModulesExtendedTest` | Discounts, subscriptions, marketplace, outbox |
| `TaxCalculationTest` | Tax-after-discount, shipping in tax base |

---

## Roadmap

Things we know we still owe the universe:

1. Refund webhook reconciliation (async PSP lifecycle) — because "we'll check the dashboard" is not a strategy
2. Outbound webhook delivery retries (throw on non-2xx)
3. Catalog update/delete API
4. PostgreSQL CI + concurrent transaction tests
5. PayPal `CHECKOUT.ORDER.APPROVED` → server-side capture trigger

See [`AGENTS.md`](AGENTS.md) for implementation constraints.

---

## License

MIT — Copyright (c) Nawras Al Bukhari nawrasalbukhari@gmail.com

Use it, fork it, vibe-code on top of it. Just run the tests first. Seriously. [See checklist above.](#testing--ci)

See license text in repository root.
