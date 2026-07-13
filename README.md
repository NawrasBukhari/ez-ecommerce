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

**Overall: 7.4/10** — strong beta commerce engine; PSP paths closing fast but still have provider-semantics blockers.  
*Current head: `606d3b7` — payment lifecycle hardening + PSP semantics fixes.*

| Area | Rating |
|------|--------|
| Package architecture | 9/10 |
| Orders and inventory | 8.5/10 |
| Checkout transaction design | 8/10 |
| Manual/null payments | 8.5/10 |
| Headless REST API | 7/10 |
| Stripe integration | 6.5/10 |
| PayPal integration | 6.5/10 |
| Telr integration | 5.5/10 |
| Tests and CI | 6/10 |

### Classification

| Tier | Verdict |
|------|---------|
| **Core / manual commerce** | Strong beta |
| **Inventory + order lifecycle** | Close to production after concurrent tests |
| **REST storefront checkout** | Functional — fail-closed price lists, address-aware quoting |
| **Stripe** | Authorization + voiding supported; refund state mapping fixed; manual capture safe |
| **PayPal** | Refund state mapping fixed (PENDING/COMPLETED/FAILED); pending capture still open |
| **Telr** | Safer sessions/refunds — capability enforcement improving |

Before treating payments as production-ready:

1. Run `vendor/bin/pest --group=hardening` on **MySQL** (CI does this).
2. Configure real webhook secrets and API tokens (never `COMMERCE_API_ALLOW_UNAUTHENTICATED=true` in prod).
3. Exercise operator commands for your PSP (`commerce:reconcile-*`). Think of them as commerce therapy for when the network blinks mid-capture.

### Recently fixed (PSP lifecycle sprint 3)

- **Stripe void argument order** — `cancel($id, [], $opts)` now passes idempotency options in the third argument (request opts), not as a cancellation parameter
- **Nullable void failure** — `VoidPaymentAuthorization` handles `PaymentResult::$failure === null` without a fatal error
- **Monotonic authorization** — out-of-order auth webhooks no longer regress `Captured`/`Cancelled`/`Refunded` payments or reactivate cancelled orders
- **Stripe partial-capture amount** — `charge.captured` webhook reads `amount_captured` (actual captured), not `amount` (intended)
- **Active session cancellation** — `CancelOrder` voids `RequiresAction`/`Pending` payments (not just `Authorized`) via `VoidPaymentAuthorization`; skips non-void gateways
- **PayPal refund correlation** — `PAYMENT.CAPTURE.REFUNDED` (PayPal's documented success event) now parsed as a refund event with `resource.id`; removed non-existent `PAYMENT.REFUND.COMPLETED`
- **Fulfillment recovery outside tx** — `UniqueConstraintViolationException` caught outside `DB::transaction` (PG-safe); payload-fingerprint mismatch rejected via `IdempotencyPayloadMismatchException`
- **Unmatched webhook persistence** — `ReconcileRefund`/`ReconcilePayment` persist `ProcessedGatewayEvent` as `unmatched` before correlation; unknown provider statuses no longer marked `processed`
- **Void reconciliation** — `ReconcileVoidAttempt` action + `commerce:reconcile-voids` command; operators can confirm/fail unknown void attempts
- **OrderPaid exactly-once outbox** — unique outbox row keyed `order.paid:{order_id}` replaces the metadata flag; concurrent finalizers and crash recovery no longer double-dispatch
- **Provider failure webhooks** — Stripe `payment_intent.payment_failed`/`canceled` and PayPal `PAYMENT.CAPTURE.DENIED` transition payments to `Failed`
- **Cancel/complete row-locking** — both actions lock the order row before transitioning
- **Orders config published** — `orders.require_fulfillment_for_completion` now in `config/ez-ecommerce.php`
- **Dedupe command** — `commerce:dedupe-transactions` cleans duplicate transactions/outbox keys before unique constraints on upgrades

### Recently fixed (payment lifecycle hardening sprint)

- **Stripe authorization reconciliation** — `payment_intent.amount_capturable_updated` → `PaymentStatus::Authorized` + Authorization transaction
- **Provider voiding** — `PaymentGateway::void()` contract; `VoidPaymentAuthorization` action; Stripe cancels PaymentIntent; `CancelOrder` voids before release
- **Stripe/PayPal refund state mapping** — `refund.updated` branches on provider status (succeeded/pending/failed/canceled); PayPal maps PENDING/COMPLETED/FAILED; `PAYMENT.REFUND.PENDING` supported
- **Refund ledger idempotency** — `UNIQUE(payment_id, type, external_id)` on transactions; `finalizeRefundAttempt` locks refund, checks existing external transaction before insert
- **OrderPaid recovery** — metadata guard as sole source of truth; recovery dispatches `OrderPaid` when it was never dispatched
- **Price-list fail-closed** — empty `pricing.allowed_price_list_codes` rejects client-selected price lists
- **Fulfillment durable idempotency** — `idempotency_key` column + unique on fulfillments; `CreateFulfillment` recovers on unique violation
- **Webhook conflict recovery** — `InboundWebhookConflictException` (409) on duplicate unique violation instead of blind ack
- **Order policy hardening** — `CompleteOrder` blocks PartiallyFulfilled + requires Fulfilled (config escape hatch); `CancelOrder` blocks PartiallyFulfilled

### Recently fixed (previous hardening sprint)

- Webhook DTO: `eventId` / `paymentReference` / `transactionReference`
- PayPal: `CHECKOUT.ORDER.APPROVED` no longer treated as captured
- Stripe: `capture_method=manual` on PaymentIntents
- Telr: verified session ref, no fake capture, refunds only on accepted response
- Dedicated capture attempts; gated session retries; checkout identity/addresses
- Fulfillment requires full PSP capture; inventory finalization → manual review
- **Latest:** capture webhook idempotency, Stripe `ch_*` ledger refs, PG idempotency fix, safer session retry

### Remaining production blockers

Most sprint blockers from the `8f5633a8` review are now addressed in code. The PSP lifecycle sprint 3 closed the Stripe cancel argument order, authorization ordering, partial capture amount, PayPal refund correlation, and PostgreSQL fulfillment recovery. Remaining gaps before calling PSP paths production-grade:

1. **Real PSP contract tests** — gateway drivers tested via fakes/mocks only; no Stripe/PayPal/Telr sandbox CI
2. **Multi-process race tests** — sequential Pest + row locks; true multi-process CI still open
3. **PayPal pending capture reconciliation** — `PAYMENT.CAPTURE.PENDING` keeps payments `Pending` but no async completion path
4. **True outbox dispatcher** — the outbox row is inserted atomically, but a dedicated outbox poller job is still host-side work

### Recently fixed (latest sprint)

- Public checkout **payment-method policy** (`checkout.public_payment_methods`)
- **Price-list eligibility** contract + validation on calculate/checkout
- **Address-aware cart quote** + matching `totals_hash`
- **Refund webhook reconciliation** (`ReconcileRefund`)
- **Pending refund** status from Stripe (no longer marked failed immediately)
- **Cart version** atomic bumps on mutations
- **Caller idempotency** on capture/refund/retry/**cancel/complete/fulfill** API endpoints
- **API 409/422** exception mapping
- **PostgreSQL CI** hardening job
- **Stripe partial capture** policy (`allow_partial_capture` config)
- **Duplicate OrderPaid** guard via `order_paid_dispatched` metadata
- **Outbound webhooks** throw on non-2xx (enables queue retries)
- **Stale pending capture** listing (`commerce:reconcile-payments --list-stale`)

### Payment driver tiers

| Tier | Status | Notes |
|------|--------|-------|
| **Manual / null checkout** | Strong beta | Idempotent checkout, inventory, capture, fulfill, refund, reconciliation |
| **Headless REST API** | Beta | Full surface; scoped tokens; guest + admin auth |
| **Stripe** | Integration prototype | Manual capture; authorization + voiding; refund state mapping; ledger uses `ch_*` — verify before live money |
| **PayPal** | Integration prototype | Order capture; refund state mapping (PENDING/COMPLETED/FAILED); pending capture still open; `PAYPAL_WEBHOOK_ID` for native verify |
| **Telr** | Sessions + refunds only | No capture; attempts rejected before PSP call |

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
- **Automated bank/PSP payouts** — payout API records commissions as paid; no bank transfers
- **Real PSP sandbox contract tests** — Stripe/PayPal/Telr live paths not in CI
- **`currency.rounding` config** — defined, unused. Schrödinger's setting.
- **PayPal `CHECKOUT.ORDER.APPROVED`** — no server-side capture trigger yet

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
| `POST` | `/cart/{id}/calculate` | `{ "shipping_method", "price_list_id"?, "shipping_address"? }` → `totals_hash` |
| `POST` | `/checkout` | See [Quick start (REST API)](#quick-start-rest-api); optional `price_list_id` |

### Order & admin routes (token required)

| Method | Path | Scope |
|--------|------|-------|
| `GET` | `/orders/{id}` | `orders.read` |
| `POST` | `/orders/{id}/capture` | `orders.write` + `Idempotency-Key` |
| `POST` | `/orders/{id}/fulfill` | `orders.write` + `Idempotency-Key` |
| `POST` | `/orders/{id}/refund` | `orders.write` + `Idempotency-Key` |
| `POST` | `/orders/{id}/retry-payment` | `orders.write` + `Idempotency-Key` |
| `POST` | `/orders/{id}/cancel` | `orders.write` + `Idempotency-Key` |
| `POST` | `/orders/{id}/complete` | `orders.write` + `Idempotency-Key` |
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
php artisan commerce:reconcile-voids --list
php artisan commerce:dedupe-transactions --dry-run
php artisan commerce:process-outbox
php artisan commerce:replay-webhooks --unmatched
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
| `PaymentOperationPolicy` | `DefaultPaymentOperationPolicy` |
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
| All green | You're ready to go. Ship it. Tell nobody you only ran the tests once. |
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
| **Hardening (PostgreSQL)** | `pest --group=hardening` on PostgreSQL 16 |

Package dev uses `testbench.yaml` so PHPStan boots without Orchestra Canvas conflicts. PHPStan and Canvas have… history.

### Test suite (149 tests, 16 files)

*149 tests can't prove your checkout works in prod, but 149 failures can prove it doesn't work in CI. Start there.*

CI: SQLite/Laravel matrix, MySQL hardening, PostgreSQL hardening (`--group=hardening`).

| File | Focus |
|------|-------|
| `CommerceFlowTest` | Boot, cart→checkout, idempotency, money |
| `HardeningTest` | Manual capture, fulfill, refund, OOS |
| `CorrectnessHardeningTest` | Unknown captures/refunds, `OrderPaid`, reconciliation |
| `PaymentLifecycleHardeningTest` | Capture/refund lifecycle, `OrderPaid` outbox exactly-once |
| `PspLifecycleSprint3Test` | PSP sprint 3: partial capture, monotonic auth, void replay, outbox, failure webhooks |
| `PackageInstallationTest` | Migration auto-load via `runsMigrations`, command registration |
| `StripeVoidStatesTest` | Stripe void across all cancellable PaymentIntent states |
| `ConcurrencyRaceTest` | Two-process MySQL/PostgreSQL race tests (fulfillment, outbox, void) |
| `ApiTest` / `ApiExtendedTest` | REST surface, token auth, capture/fulfill |
| `SecurityTest` | Fail-closed API, scopes, webhook auth |
| `SprintApiTest` | Customers, cart merge, retry payment, returns |
| `BacklogApiTest` / `BacklogImplementationTest` | Admin APIs, categories, webhooks, cart expiry |
| `SchemaAndRelationsTest` | Migrations (51), FK integrity |
| `ModulesTest` / `ModulesExtendedTest` | Discounts, subscriptions, marketplace, outbox |
| `TaxCalculationTest` | Tax-after-discount, shipping in tax base |

---

## Roadmap

1. Real Stripe/PayPal/Telr contract tests (sandbox)
2. Catalog update/delete API
3. PayPal `CHECKOUT.ORDER.APPROVED` → server-side capture trigger
4. Automated PSP payout transfers (bank transfers, not just ledger records)
5. `currency.rounding` config (currently unused)

See [`AGENTS.md`](AGENTS.md) for implementation constraints.

---

## License

MIT — Copyright (c) Nawras Al Bukhari nawrasalbukhari@gmail.com

Use it, fork it, vibe-code on top of it. Just run the tests first. Seriously. [See checklist above.](#testing--ci)

See license text in repository root.
