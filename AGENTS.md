# AGENTS.md — AI assistant guide for ez-ecommerce

> **If you are a human:** this file is for Cursor, Copilot, Claude Code, and other agents.  
> **If you are an agent:** read this before touching code. The humans vibe-coded this package with ChatGPT and Claude; your job is to not un-vibe it.

---

## What this package is

**ez-ecommerce** is a headless Laravel commerce **engine** (not a storefront). It owns:

- `commerce_*` database tables and migrations (53 files)
- Cart → checkout → order → payment → fulfillment → refund → return flows
- Inventory reservations with signed movements
- Versioned REST API at `api/ez-commerce/v1`
- Subscriptions with period billing, marketplace commissions, B2B net terms, inbound/outbound webhooks

Namespace: `EzEcommerce\`  
Facade: `EzEcommerce` → `CommerceManager`  
Config: `config/ez-ecommerce.php`  
Commands: `commerce:*` (never `commerce:migrate` — host runs `php artisan migrate`)

---

## Sprint status (current)

### Shipped (PSP lifecycle sprint 6 — post-review fixes)

| Area | What landed |
|------|-------------|
| **Initial capture decline** | `HandleCaptureResult::handleFailed` derives payment status from ledger: zero captures + terminal `Failed` result → `PaymentStatus::Failed` (no more stuck `Authorized`); partial captures preserved |
| **Deferred reversal replay** | Early reversal webhooks (arriving before capture) are stored as `deferred` (not `processed`); after a completion webhook captures, `ReconcilePayment` replays deferred reversals automatically — reversal is never permanently lost under out-of-order delivery |
| **Pending attempt resolution** | Completion webhook locates the original pending capture attempt by provider reference and marks it resolved (no more stale pending attempts after capture) |
| **Operation-specific conflict rules** | `AssertNoConflictingPaymentOperation` uses operation-specific in-flight rules: pending `create_session` with external_id is settled, but pending `capture` with external_id (PayPal PENDING) is still in flight and blocks void/refund |
| **Same-operation race guards** | `VoidPaymentAuthorization` and `RetryPaymentSession` now check for existing pending/unknown same-operation attempts inside the lock (no more concurrent void×2 or session×2) |
| **Non-fatal outbox enqueue** | `DB::afterCommit` dispatch in `FinalizeAcceptedPayment` catches enqueue failures silently — the outbox row is already committed; `commerce:process-outbox` is the durable fallback |
| **Outbox claim tokens** | `lock_token` column + UUID per claim; completion and failure updates verify token ownership; `processed` is terminal (stale workers cannot regress it); backoff enforced on direct dispatch |
| **Dedupe accounting fixes** | Full-refund check is `refunded >= captured` (not `>= amount`); `order.refunded_total_minor` rebuilt from payment ledger; canonical selection prefers succeeded over earlier failed; dry-run never writes |
| **Failed → Pending blocked** | `RefundTransitionPolicy` blocks `Failed → Pending` (delayed pending webhooks cannot regress a failed refund) |
| **MySQL index removal** | `DedupeTransactionsTest` uses driver-aware index removal (MySQL `ALTER TABLE DROP INDEX`, SQLite/PostgreSQL `DROP INDEX IF EXISTS`) |
| **Race assertions strengthened** | Capture-vs-expiry expires reservations and asserts consistent inventory; outbox claim asserts exactly one claimer; worker narrows benign `UniqueConstraintViolationException` to idempotent insert actions only |
| **Tests** | 209 Pest tests across 25 feature files |

### Shipped (PSP lifecycle sprint 5 — full hardening)

| Area | What landed |
|------|-------------|
| **Capture-result semantics** | `HandleCaptureResult` dispatches on gateway `PaymentStatus` (not `success`); PayPal `PENDING` finalizes nothing (no ledger/commit/confirm/outbox) until completion webhook; `PayPalCaptureSemanticsTest` |
| **Reversal + dispute** | `PaymentStatus::Reversed`, `OrderPaymentStatus::Disputed`, `PaymentTransactionType::Reversal`; `ApplyPaymentReversal` action appends reversal ledger + manual-review metadata; `ReconcilePayment` reversal branch before failure; terminal-monotonic (completion after reversal does not restore); `PaymentReversalTest` |
| **Transactional at-least-once outbox** | New migration extends `commerce_outbox_messages` (`available_at`, `locked_at`, `locked_until`, `attempts`, `last_error`); `ProcessOutboxJob` exclusive claim + lease + exponential backoff; `FinalizeAcceptedPayment` atomic insert + `DB::afterCommit` dispatch; `commerce:process-outbox` poller; `OutboxWorkerTest` |
| **Dedupe aggregate rebuild** | `commerce:dedupe-transactions` separates `authorized`/`captured`/`refunded`/`reversed` aggregates (PostgreSQL-safe `havingRaw`); status precedence from ledger; `--dry-run`/`--payment`/`--outbox`; `DedupeTransactionsTest` |
| **Monotonic refunds** | `RefundTransitionPolicy` (`Succeeded` terminal, `Failed → Succeeded` via reconciliation); `ReconcileRefund` guards pending/failed branches; `charge.refunded` demoted to informational; `RefundMonotonicTest` |
| **Payment-wide conflict guard** | `AssertNoConflictingPaymentOperation` (capture/void/refund/create_session compatibility matrix); injected into `CapturePayment`/`VoidPaymentAuthorization`/`RefundPayment`/`RetryPaymentSession` inside the lock; `ConflictingPaymentOperationException` → HTTP 409; `PaymentConflictGuardTest` |
| **Complete void idempotency** | `failed` → cached terminal failure (no duplicate row); `failed_retryable` → reuse attempt; payload-hash mismatch rejection; tests for every state |
| **Partial-capture ordering** | Stripe partial-capture config check before attempt row creation (no `pending`/`unknown` orphan); replay marks attempt terminally `failed` if disabled later |
| **Refund policy enforcement** | `PaymentOperationPolicy::canRefund()` guarded inside lock before reserving funds; rejected for cancelled/failed/authorized-only/pending/fully-refunded/zero-balance |
| **Multi-process test harness** | `tests/bin/worker.php` reads `DB_*`, no `RefreshDatabase`, JSON stdout, exit codes; `ConcurrencyRaceTest` in `tests/Races` with 8 two-process races; `RaceTestCase` disables per-test txn so children see committed data |
| **Isolated installation test** | `PackageInstallationTest` in `tests/Installation` via `InstallationTestCase` (no `loadMigrationsFrom`); verifies `runsMigrations()` discovery, columns/indexes, unique constraints, commands |
| **Tests** | 208 Pest tests across 24 feature files (sprint 5; 209/25 after sprint 6) |

### Shipped (PSP lifecycle sprint 4)

| Area | What landed |
|------|-------------|
| **Package installation** | `discoversMigrations()` + `runsMigrations()` + full `hasCommands([...])` in `EzEcommerceServiceProvider`; `PackageInstallationTest` guards the surface |
| **Payment operation policy** | `PaymentOperationPolicy` contract + `DefaultPaymentOperationPolicy`; order-status guards in `CapturePayment`/`RetryPaymentSession`; replaces inline voidable-state lists in `VoidPaymentAuthorization`/`CancelOrder` |
| **Stripe void states** | `void()` accepts all cancellable PI states (`requires_payment_method`, `requires_confirmation`, `requires_action`, `requires_capture`); already-cancelled is idempotent; `StripeVoidStatesTest` |
| **Outbox worker** | `status` column on `commerce_outbox_messages`; `ProcessOutboxJob` + `commerce:process-outbox` command; `FinalizeAcceptedPayment` inserts `pending` row only (no inline dispatch); crash-recovery drains pending rows |
| **Webhook replay** | `commerce:replay-webhooks` command; `unmatched` `ProcessedGatewayEvent` rows are replayable (not terminal) in `ReconcilePayment`/`ReconcileRefund` |
| **PostgreSQL conflict safety** | `insertOrIgnore` replaces in-txn caught `UniqueConstraintViolationException` in `VoidPaymentAuthorization`, `ReconcileVoidAttempt`, `RefundPayment` |
| **Stripe refund events** | `refund.created`/`updated`/`failed` parsed; local identifiers (`refund_public_id`, `payment_attempt_public_id`) attached to Stripe refund metadata; `ReconcileRefund` correlates by metadata |
| **PayPal v2 capture** | `PAYMENT.CAPTURE.PENDING`/`DECLINED`/`REVERSED` parsed; `capture()` maps `PENDING` → `PaymentStatus::Pending`; `isFailureEvent` uses `DECLINED`/`REVERSED` (not `DENIED`) |
| **Void idempotency replay** | `VoidPaymentAuthorization` looks up existing attempt by key; succeeded → idempotent return, pending/unknown → throw |
| **Race tests** | `ConcurrencyRaceTest` + `tests/bin/worker.php` for two-process MySQL/PostgreSQL races (fulfillment, outbox, void); skipped on SQLite |
| **Dedupe recalc** | `commerce:dedupe-transactions` recalculates `captured_minor`/`refunded_minor` and order payment projections after dedup |
| **Tests** | 149 Pest tests across 16 feature files |

### Shipped (PSP lifecycle sprint 3)

| Area | What landed |
|------|-------------|
| **Stripe void args** | `cancel($id, [], $opts)` — idempotency options in the third argument, not as a cancellation parameter |
| **Nullable void failure** | `VoidPaymentAuthorization` handles `failure === null` without a fatal error |
| **Monotonic authorization** | Auth webhooks only transition from `{Created, Pending, RequiresAction}`; never regress terminal states or reactivate cancelled orders |
| **Stripe partial capture** | `charge.captured` reads `amount_captured` (actual captured), not `amount` (intended) |
| **Active session cancel** | `CancelOrder` voids `RequiresAction`/`Pending`/`Authorized` payments; skips non-void gateways |
| **PayPal refund correlation** | `PAYMENT.CAPTURE.REFUNDED` parsed as refund event with `resource.id`; removed non-existent `PAYMENT.REFUND.COMPLETED` |
| **Fulfillment recovery** | `UniqueConstraintViolationException` caught outside `DB::transaction` (PG-safe); payload-fingerprint mismatch rejected |
| **Unmatched webhook persistence** | `ReconcileRefund`/`ReconcilePayment` persist `unmatched` before correlation; unknown provider statuses no longer marked `processed` |
| **Void reconciliation** | `ReconcileVoidAttempt` action + `commerce:reconcile-voids` command |
| **OrderPaid outbox** | Unique outbox row keyed `order.paid:{order_id}` replaces metadata flag; at-least-once under concurrency + crash recovery (idempotent consumers required) |
| **Failure webhooks** | Stripe `payment_intent.payment_failed`/`canceled` and PayPal `PAYMENT.CAPTURE.DENIED` → `PaymentStatus::Failed` |
| **Cancel/complete locking** | Both actions `lockForUpdate` the order row before transitioning |
| **Config + commands** | `orders.require_fulfillment_for_completion` published; `commerce:dedupe-transactions` for upgrade preflight |
| **Tests** | 130 Pest tests across 15 feature files (12 new PSP lifecycle tests) |

### Shipped (payment lifecycle hardening sprint)

| Area | What landed |
|------|-------------|
| **Authorization** | Stripe `payment_intent.amount_capturable_updated` reconciliation → `PaymentStatus::Authorized` + Authorization transaction; reservation expiry now protects authorized payments |
| **Voiding** | `PaymentGateway::void()` contract + `void` capability flag; `VoidPaymentAuthorization` action; Stripe cancels PaymentIntent on void; `CancelOrder` voids authorized payments before release |
| **Refund state mapping** | Stripe `refund.updated` branches on provider status (succeeded/pending/failed/canceled) instead of assuming success; PayPal `refund()` maps `PENDING`/`COMPLETED`/`FAILED`; PayPal `parseWebhook` exposes refund refs + status; `PAYMENT.REFUND.PENDING` supported |
| **Refund ledger idempotency** | `UNIQUE(payment_id, type, external_id)` on `commerce_payment_transactions`; `finalizeRefundAttempt` locks refund row, reloads status, checks existing external transaction before insert; unique-violation recovery re-syncs from ledger |
| **OrderPaid recovery** | `completeOrderAfterCapture` uses `order_paid_dispatched` metadata guard as sole source of truth (removed `wasFullyCaptured` param); recovery finalization now dispatches `OrderPaid` when it was never dispatched |
| **Price-list eligibility** | `DefaultPriceListEligibility` fail-closed by default — empty `pricing.allowed_price_list_codes` rejects client-selected price lists; hosts opt in via config |
| **Fulfillment idempotency** | `idempotency_key` column + unique constraint on `commerce_fulfillments`; `CreateFulfillment` accepts key, stores it, recovers existing on unique violation; `OrderManager::fulfill` + `OrderController` thread the key |
| **Webhook conflict recovery** | `InboundWebhookConflictException` on duplicate unique violation; `ReconcilePayment`/`ReconcileRefund` reload inbox record and throw unless `processed`; controller maps to 409 |
| **Order policies** | `CompleteOrder` blocks `PartiallyFulfilled` + requires `Fulfilled` unless `orders.require_fulfillment_for_completion=false`; `CancelOrder` blocks `PartiallyFulfilled` + voids authorized payments before release |
| **Tests** | 118 Pest tests across 14 feature files (16 new payment lifecycle tests) |

### Shipped (previous hardening sprint)

| Area | What landed |
|------|-------------|
| **Payments** | Capture webhook idempotency; Stripe `ch_*` ledger refs; pending refund status; refund webhook reconcile; session retry safety; Telr capture guard |
| **Checkout** | Public `checkout.public_payment_methods` policy; address-aware quote + `totals_hash`; price-list eligibility contract |
| **Idempotency** | Required `Idempotency-Key` on checkout, capture, refund, retry, cancel, complete, fulfill; PG unique-violation recovery |
| **Cart** | Atomic `version` bumps on mutations; 409/422 API conflict mapping |
| **Webhooks** | Outbound delivery throws on non-2xx (queue retries); `ReconcileRefund` for async PSP refunds |
| **CI** | PostgreSQL hardening job (`pest --group=hardening`) |

### Shipped (earlier sprints)

| Area | What landed |
|------|-------------|
| **Orders** | Cancel/complete API, transitions/fulfillments/refunds/payments read |
| **Marketplace** | Commission + payout history read APIs |
| **Subscriptions** | Subscription plans CRUD API |
| **Customers** | Customer groups API + `customer_group_id` on create |
| **Catalog** | Public categories, product filters, store scoping, category attach on create |
| **Inventory** | Transfer, adjust, deactivate, movements read, reservation release |
| **Cart** | Expiry enforcement, `price_list_id` on calculate/checkout |
| **Shipping/Tax** | `GET /shipping-methods`, weight shipping + jurisdiction tax drivers |
| **Webhooks** | Delivery retry, more outbound events, inbound event log API |
| **Commands** | `commerce:purge-expired-carts`, `commerce:purge-idempotency-records`, `commerce:reconcile-payments`, `commerce:reconcile-refunds`, `commerce:reconcile-finalizations`, `commerce:reconcile-voids`, `commerce:dedupe-transactions`, `commerce:process-outbox`, `commerce:replay-webhooks` |

### Still not built (do not assume)

- Storefront / admin UI
- Catalog update/delete API
- Automated PSP payout transfers (payout records commissions as paid)
- `currency.rounding` config (unused)
- Real Stripe/PayPal/Telr sandbox contract tests (deferred — need live credentials)

---

## Locked architecture rules (do not break)

1. **`Purchasable` has no price method.** Always resolve price via `PriceResolver` / `PricingContext`.
2. **Checkout returns `CheckoutResult`**, not a bare `Order`.
3. **Never call payment gateways inside DB transactions.** `PlaceOrder` commits commercial state first, then `CreatePaymentSession` runs outside the inner commercial transaction. `IdempotencyStore` also runs the checkout callback outside any DB transaction (short txns only for record lock + completion/failure).
4. **Refunds ≠ returns ≠ restock.** Three separate action families; do not merge them.
5. **Polymorphic refs use morph aliases** (`commerce_product_variant`, etc.), not FQCNs. Register host models via `EzEcommerce::morphMap([...])`.
6. **Orders, payments, inventory models are package-controlled** — not swappable via config class names.
7. **Idempotency is required** for checkout and financial/order mutations on the API (`Idempotency-Key` header on checkout, capture, refund, retry-payment, cancel, complete, fulfill). Reused keys replay the cached terminal result; a reused key with a different payload throws `IdempotencyPayloadMismatchException`; `failed` void keys do **not** create a duplicate row.
8. **Money is always integer minor units** via `EzEcommerce\Core\Money\Money`. Never use floats for money.
9. **The outbox is at-least-once.** `order.paid` outbox delivery may repeat across crashes/restarts; consumers must be idempotent. The unique `order.paid:{order_id}` key prevents duplicate *inserts*, not duplicate *deliveries*. Never call payment gateways inside the outbox dispatch path.
10. **Reversal is terminal.** A `Reversed` payment never auto-restores to `Captured`; the order goes to `Disputed` for manual review. `ApplyPaymentReversal` appends one `Reversal` ledger row and is idempotent on `external_id`.
11. **Lazy senior dev mode:** smallest correct diff; no new abstractions unless asked; no new dependencies unless necessary.

---

## Directory map

```
src/
  Api/              REST controllers, resources, middleware (guest cart, API token, checkout access)
  B2B/              Company model + net terms
  Cart/             CartManager + cart actions (single ApplyDiscountCode in Cart/Actions)
  Catalog/          Product, ProductVariant, Category, contracts
  Checkout/         CheckoutManager, CheckoutBuilder, PlaceOrder
  Commands/         commerce:install, purge, renew, reconcile-payments/refunds/finalizations
  Core/             Money, Clock, Idempotency, enums, events, OutboxMessage
  Customers/        Customer, Address, CustomerResolver
  Discounts/        Discount model only (cart actions live in Cart/)
  Fulfillment/      CreateFulfillment
  Inventory/        Reservations, movements, warehouses
  Marketplace/      Vendor, RecordVendorCommissions
  Orders/           OrderManager (fulfill), OrdersManager (lookup/recalc)
  Payments/         Gateways + capture/refund/session/reconcile/retry actions
  Pricing/          DefaultPriceResolver, Price, PriceList
  Refunds/          RefundPayment
  Returns/          Return request/receive/restock/mark-damaged
  Shipping/         FlatShippingCalculator + driver resolver
  Stores/           Store model + StoreContext
  Subscriptions/    Plans, create/renew/bill
  Taxes/            SimpleTaxCalculator + driver resolver
  Webhooks/         Inbound controller + outbound dispatch/delivery
routes/api.php      Versioned REST API
database/migrations commerce_* tables
tests/Feature/      Pest feature tests — see README test matrix
tests/Races/        Two-process MySQL/PostgreSQL race tests (RaceTestCase, no per-test txn)
tests/Installation/ Isolated package-installation regression test (InstallationTestCase)
```

---

## Public API surface (what agents should use)

### Facade (`EzEcommerce::`)

| Method | Returns | Use for |
|--------|---------|---------|
| `cart()` | `CartManager` | Guest carts, items, discounts, totals, merge |
| `checkout()` | `CheckoutManager` | Fluent checkout → `place()` |
| `catalog()` | `CatalogManager` | Lookup product/variant by slug, SKU, public_id |
| `inventory()` | `InventoryManager` | Receive stock, release expired reservations |
| `orders()` | `OrdersManager` | Find order, recalc payment/fulfillment status |
| `morphMap([...])` | void | Register custom morph aliases |

### CartManager methods

`createGuest`, `addItem`, `updateItem`, `removeItem`, `applyDiscount`, `removeDiscount`, `calculateTotals`, `totalsHash`, `merge`

### Not on the facade (inject actions or managers directly)

- `OrderManager` — `fulfill()` (used by API `OrderController`)
- `RefundPayment`, `CreateFulfillment`, `CapturePayment`, `RetryPaymentSession`, `ReconcilePayment`
- Returns: `CreateReturnRequest`, `ReceiveReturn`, `RestockReturnedItem`, `MarkReturnedItemAsDamaged`
- Subscriptions: `CreateSubscription`, `RenewSubscription`, `BillSubscriptionPeriod`

---

## REST API auth (memorize this)

| Routes | Auth |
|--------|------|
| Products read, `POST /cart/guest` | Public |
| Cart CRUD, discount, calculate | `X-Guest-Cart-Token` |
| `POST /checkout` | `X-Guest-Cart-Token` + `Idempotency-Key`; payment method must be in `checkout.public_payment_methods` (default: stripe, paypal, telr) |
| `POST /orders/{id}/capture`, `/refund`, `/retry-payment`, `/cancel`, `/complete`, `/fulfill` | Bearer token + scope + `Idempotency-Key` |
| Protected routes | Bearer token + **scope** (e.g. `catalog.write`, `orders.read`) |
| `POST /customers/{id}/cart` | `customers.write` |
| `POST /products` | `catalog.write` |
| `POST /warehouses/{id}/receive` | `inventory.write` |
| `POST /vendors/{id}/payouts` | `marketplace.write` |
| Inbound webhooks | Stripe signature, PayPal native verify (`PAYPAL_WEBHOOK_ID`) or shared secret |

Empty `COMMERCE_API_TOKEN` with no scoped tokens → protected routes return **503**.

Scoped tokens via `COMMERCE_API_*_TOKEN` env vars in config `api.scoped_tokens`. `*` = admin. `.write` implies `.read` for the same prefix.

---

## Payment methods / gateways

| Key | Gateway class | Production-ready? |
|-----|---------------|-------------------|
| `manual` | ManualPaymentGateway | Yes (admin capture) |
| `null` | NullPaymentGateway | Zero-total orders only |
| `fake` | FakePaymentGateway | Tests / local webhooks only |
| `net_terms` | ManualPaymentGateway (alias) | B2B metadata only |
| `stripe` | StripePaymentGateway | Partial — manual capture; ledger uses `ch_*`; verify before live |
| `paypal` | PayPalPaymentGateway | Partial — order capture; native webhook verify when `PAYPAL_WEBHOOK_ID` set |
| `telr` | TelrPaymentGateway | Sessions + refunds only — no capture; attempts rejected before PSP call |

---

## Feature flags (`config/ez-ecommerce.php`)

`api` defaults `true`. `subscriptions`, `marketplace`, `multi_store`, `b2b`, and `outbound_webhooks` default **`false`** — enable in host config (tests enable all in `TestCase`). Disabling only stops gated code paths; tables still migrate.

Public checkout payment methods: `checkout.public_payment_methods` (default `['stripe', 'paypal', 'telr']`). Facade/SDK checkout is not restricted unless you pass `restrictPublicPaymentMethods` to `PlaceOrder`.

| Flag | What works | Gaps |
|------|------------|------|
| `api` | Full REST surface + bearer auth | Inventory ops, order lifecycle reads |
| `subscriptions` | CRUD API, plans API, renew + `BillSubscriptionPeriod` | No PSP dunning |
| `marketplace` | Commissions + vendor API + payout/commission reads | No bank transfers |
| `multi_store` | `store_id`, header, stores API, product scoping | No per-store policies |
| `b2b` | `net_terms`, companies API | No credit limits |
| `outbound_webhooks` | Outbox + signed delivery jobs | Host must run queue worker |

---

## Safe extension points

| Contract | Default | Register in |
|----------|---------|-------------|
| `PaymentGateway` | Per `drivers.payment.default` | Host service provider |
| `PaymentOperationPolicy` | `DefaultPaymentOperationPolicy` | `PaymentsServiceProvider` |
| `PriceResolver` | `DefaultPriceResolver` | `PricingServiceProvider` |
| `TaxCalculator` | `SimpleTaxCalculator` | `TaxesServiceProvider` (`drivers.tax.default`) |
| `ShippingCalculator` | `FlatShippingCalculator` | `ShippingServiceProvider` (`drivers.shipping.default`) |
| `CustomerResolver` | `DefaultCustomerResolver` | `CustomersServiceProvider` |
| `ReservationPolicy` | `ConfigReservationPolicy` | `InventoryServiceProvider` |
| `StoreContext` | `DefaultStoreContext` | `StoresServiceProvider` |

**Not swappable via config:** Order, Payment, Cart, Inventory models.

---

## Common agent mistakes

1. **Adding `price()` to `Purchasable`** — rejected by design.
2. **Calling Stripe inside `DB::transaction`** — causes deadlocks and double charges.
3. **Using FQCN in `purchasable_type`** — breaks morph map; use aliases.
4. **Expecting `features.*` to add routes** — `api` flag gates the API provider; `subscriptions`, `marketplace`, `b2b`, and `outbound_webhooks` default **off** and gate their service providers. Enable in config (and in tests via `TestCase`) before using those modules.
5. **Using `country` column on addresses** — DB column is `country_code`; API accepts `country` in JSON.
6. **Assuming tests = full PSP coverage** — Stripe/PayPal/Telr live sandbox paths are not contract-tested in CI. This is the single biggest remaining honesty gap.
7. **Skipping `Idempotency-Key` on order mutations** — cancel, complete, fulfill, capture, refund, and retry-payment all require it on the API.
8. **Creating `commerce:migrate`** — migrations auto-load; host runs `migrate`.
9. **Assuming the outbox is exactly-once** — it is at-least-once; consumers must be idempotent. The unique key prevents duplicate inserts, not duplicate deliveries.
10. **Treating a `failed` void key as retriable with the same key** — `failed` is terminal; replay returns the cached failure without a new row. Use a new key for a fresh void attempt.
11. **Restoring a `Reversed` payment to `Captured`** — reversal is terminal; a late completion webhook must not un-reverse.
12. **Marking a deferred reversal as `processed`** — an early reversal webhook (before capture) must be stored as `deferred` and replayed after completion; marking it `processed` permanently loses the reversal.
13. **Comparing refunded against the original amount for full-refund status** — full refund is relative to captured funds (`refunded >= captured`), not the original payment amount.
14. **Regressing a `Failed` refund to `Pending`** — `Failed` is terminal except via explicit reconciliation to `Succeeded`; a delayed pending webhook must not regress it.
15. **Treating pending + external_id as settled for all operations** — a pending `create_session` with external_id is settled, but a pending `capture` with external_id (PayPal PENDING) is still in flight and blocks void/refund.

---

## Commands to run (Windows PowerShell)

```powershell
cd c:\Users\nawra\Herd\ez-ecommerce
composer test
vendor\bin\pest
vendor\bin\pint --dirty
vendor\bin\phpstan analyse
```

Test harness: Orchestra Testbench, SQLite in-memory (default), MySQL 8 / PostgreSQL 16 for `--group=hardening`. `tests\TestCase.php` sets `COMMERCE_API_TOKEN=test-api-token` and `loadMigrationsFrom`. `tests/Races/` uses `RaceTestCase` (no per-test transaction, so child worker processes see committed data). `tests/Installation/` uses `InstallationTestCase` (no `loadMigrationsFrom` — proves `runsMigrations()` discovery). Race workers (`tests/bin/worker.php`) read `DB_*` env vars and print JSON.

---

## When adding a feature

1. Read the nearest existing action in the same domain — match style (final class, constructor injection, `execute()`).
2. Add migration if schema changes (`commerce_*` prefix).
3. Register in module `*ServiceProvider` only if binding a contract.
4. Expose via manager or API only if the user asked for public API.
5. Add at least one Pest feature test for non-trivial logic.
6. Update README "What works / What doesn't" and this file if behavior changes.

---

## Out of scope for agents unless explicitly requested

- Building a storefront / Blade / Livewire UI
- Splitting into multiple Composer packages
- Full Stripe/PayPal native webhook signature verification
- Subscription billing engine (Stripe Billing, etc.)
- Vendor payout automation
- Admin dashboard

---

## Origin note

This package was vibe-coded through long architecture sessions. Treat the design decisions above as intentional, not accidents. When in doubt: **smaller diff, boring code, one test.**
