# AGENTS.md — AI assistant guide for ez-ecommerce

> **If you are a human:** this file is for Cursor, Copilot, Claude Code, and other agents.  
> **If you are an agent:** read this before touching code. The humans vibe-coded this package with ChatGPT and Claude; your job is to not un-vibe it.

---

## What this package is

**ez-ecommerce** is a headless Laravel commerce **engine** (not a storefront). It owns:

- `commerce_*` database tables and migrations (44 files)
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

### Shipped this sprint

| Area | What landed |
|------|-------------|
| **Security** | Fail-closed API token, checkout cart token, inbound webhook auth, capture allowlist |
| **API** | Customers, addresses, returns, cart merge, retry payment, stores/companies/vendors/subscriptions |
| **Cart** | `removeDiscount()` on manager + `DELETE /cart/{id}/discount` |
| **Subscriptions** | `BillSubscriptionPeriod` on `commerce:renew-subscriptions` |
| **Webhooks** | Inbound routes, outbound outbox + delivery jobs |
| **Payments** | Telr refund HTTP call; `POST /orders/{id}/retry-payment` |
| **Tests** | 42 Pest tests across 8 feature files |

### Still not built (do not assume)

- Storefront / admin UI
- Customer cart creation API (merge expects an existing customer cart)
- Inventory admin REST API
- Marketplace vendor payouts
- Native PayPal webhook crypto verification (shared-secret gate exists)
- `currency.rounding` config (unused)
- Per-endpoint RBAC (single `COMMERCE_API_TOKEN` = full admin API)

---

## Locked architecture rules (do not break)

1. **`Purchasable` has no price method.** Always resolve price via `PriceResolver` / `PricingContext`.
2. **Checkout returns `CheckoutResult`**, not a bare `Order`.
3. **Never call payment gateways inside DB transactions.** `PlaceOrder` commits commercial state first, then `CreatePaymentSession` runs outside the transaction.
4. **Refunds ≠ returns ≠ restock.** Three separate action families; do not merge them.
5. **Polymorphic refs use morph aliases** (`commerce_product_variant`, etc.), not FQCNs. Register host models via `EzEcommerce::morphMap([...])`.
6. **Orders, payments, inventory models are package-controlled** — not swappable via config class names.
7. **Idempotency is required for checkout** (API enforces `Idempotency-Key` header).
8. **Money is always integer minor units** via `EzEcommerce\Core\Money\Money`. Never use floats for money.
9. **Lazy senior dev mode:** smallest correct diff; no new abstractions unless asked; no new dependencies unless necessary.

---

## Directory map

```
src/
  Api/              REST controllers, resources, middleware (guest cart, API token, checkout access)
  B2B/              Company model + net terms
  Cart/             CartManager + cart actions (single ApplyDiscountCode in Cart/Actions)
  Catalog/          Product, ProductVariant, Category, contracts
  Checkout/         CheckoutManager, CheckoutBuilder, PlaceOrder
  Commands/         commerce:install, release reservations, renew subscriptions
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
tests/Feature/      42 Pest tests — see README test matrix
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
| Products, `POST /cart/guest` | Public |
| Cart CRUD, discount, calculate | `X-Guest-Cart-Token` |
| `POST /checkout` | `X-Guest-Cart-Token` + `Idempotency-Key` |
| Orders, returns, customers, admin CRUD, `POST /cart/merge` | `Authorization: Bearer {COMMERCE_API_TOKEN}` or `X-Commerce-Api-Token` |
| Inbound webhooks | Stripe signature or `X-Commerce-Webhook-Secret` |

Empty `COMMERCE_API_TOKEN` → protected routes return **503** (fail closed).

Boolean env vars (`COMMERCE_API_ALLOW_UNAUTHENTICATED`, `COMMERCE_INBOUND_WEBHOOK_ALLOW_UNSIGNED`) are parsed with `filter_var(..., FILTER_VALIDATE_BOOLEAN)` in config.

---

## Payment methods / gateways

| Key | Gateway class | Production-ready? |
|-----|---------------|-------------------|
| `manual` | ManualPaymentGateway | Yes (admin capture) |
| `null` | NullPaymentGateway | Zero-total orders only |
| `fake` | FakePaymentGateway | Tests / local webhooks only |
| `net_terms` | ManualPaymentGateway (alias) | B2B metadata only |
| `stripe` | StripePaymentGateway | Partial — SDK + webhook verify when secret set |
| `paypal` | PayPalPaymentGateway | Partial — HTTP + shared-secret inbound webhooks |
| `telr` | TelrPaymentGateway | Partial — sessions + refund HTTP; capture is optimistic |

---

## Feature flags (`config/ez-ecommerce.php`)

All default `true`. Disabling only stops gated code paths; tables still migrate.

| Flag | What works | Gaps |
|------|------------|------|
| `api` | Full REST surface + bearer auth | No inventory admin API |
| `subscriptions` | CRUD API, renew + `BillSubscriptionPeriod` | No PSP dunning |
| `marketplace` | Commissions + vendor API | No payouts |
| `multi_store` | `store_id`, header, stores API | No per-store policies |
| `b2b` | `net_terms`, companies API | No credit limits |
| `outbound_webhooks` | Outbox + signed delivery jobs | Host must run queue worker |

---

## Safe extension points

| Contract | Default | Register in |
|----------|---------|-------------|
| `PaymentGateway` | Per `drivers.payment.default` | Host service provider |
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
4. **Expecting `features.*` to add routes** — `api` flag gates the API provider; others gate listeners/metadata.
5. **Using `country` column on addresses** — DB column is `country_code`; API accepts `country` in JSON.
6. **Assuming tests = full PSP coverage** — Stripe/PayPal/Telr live paths are not integration-tested.
7. **Creating `commerce:migrate`** — migrations auto-load; host runs `migrate`.

---

## Commands to run (Windows PowerShell)

```powershell
cd c:\Users\nawra\Herd\ez-ecommerce
composer test
vendor\bin\pest
vendor\bin\pint --dirty
vendor\bin\phpstan analyse
```

Test harness: Orchestra Testbench, SQLite in-memory, `tests\TestCase.php` sets `COMMERCE_API_TOKEN=test-api-token`.

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
