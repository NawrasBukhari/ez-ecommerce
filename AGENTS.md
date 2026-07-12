# AGENTS.md — AI assistant guide for ez-ecommerce

> **If you are a human:** this file is for Cursor, Copilot, Claude Code, and other agents.  
> **If you are an agent:** read this before touching code. The humans vibe-coded this package with ChatGPT and Claude; your job is to not un-vibe it.

---

## What this package is

**ez-ecommerce** is a headless Laravel commerce **engine** (not a storefront). It owns:

- `commerce_*` database tables and migrations
- Cart → checkout → order → payment → fulfillment → refund flows
- Inventory reservations with signed movements
- Optional REST API, subscriptions scaffolding, marketplace commissions, B2B net terms, outbound webhooks

Namespace: `EzEcommerce\`  
Facade: `EzEcommerce` → `CommerceManager`  
Config: `config/ez-ecommerce.php`  
Commands: `commerce:*` (never `commerce:migrate` — host runs `php artisan migrate`)

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
  Api/              REST controllers, resources, GuestCartToken middleware
  B2B/              Company model (net terms metadata only)
  Cart/             CartManager + cart actions
  Catalog/          Product, ProductVariant, Category, contracts
  Checkout/         CheckoutManager, CheckoutBuilder, PlaceOrder
  Commands/         commerce:install, release reservations, renew subscriptions
  Core/             Money, Clock, Idempotency, enums, events, CommerceModel
  Customers/        Customer, Address, CustomerResolver
  Discounts/        Discount model + standalone actions (partially duplicated in Cart/)
  Fulfillment/      CreateFulfillment
  Inventory/        Reservations, movements, warehouses
  Marketplace/      Vendor, RecordVendorCommissions (on order create)
  Orders/           OrderManager (fulfill), OrdersManager (lookup/recalc)
  Payments/         Gateways + capture/refund/session actions
  Pricing/          DefaultPriceResolver, Price, PriceList
  Refunds/          RefundPayment
  Returns/          Return request/receive/restock/damaged
  Shipping/         FlatShippingCalculator only
  Stores/           Store model + StoreContext
  Subscriptions/    Plans, create/renew (no billing charge)
  Taxes/            SimpleTaxCalculator only
  Webhooks/         Outbound dispatch + inbound models (no routes)
routes/api.php      Versioned REST API
database/migrations commerce_* tables (44 migrations)
tests/Feature/      17 Pest tests — core path only, not full coverage
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

### Not on the facade (inject actions or managers directly)

- `OrderManager` — `fulfill()` (used by API `OrderController`)
- `RefundPayment`, `CreateFulfillment`, `CapturePayment`
- Returns: `CreateReturnRequest`, `ReceiveReturn`, `RestockReturnedItem`, `MarkReturnedItemAsDamaged`
- Subscriptions: `CreateSubscription`, `RenewSubscription`
- Payments: `ReconcilePayment`, `RetryPaymentSession` (unwired)

---

## Payment methods / gateways

| Key | Gateway class | Production-ready? |
|-----|---------------|-------------------|
| `manual` | ManualPaymentGateway | Yes (admin capture) |
| `null` | NullPaymentGateway | Zero-total orders only |
| `fake` | FakePaymentGateway | Tests only |
| `net_terms` | ManualPaymentGateway (alias) | B2B metadata only; no auto-invoice |
| `stripe` | StripePaymentGateway | Partial — optional SDK, weak webhook verify |
| `paypal` | PayPalPaymentGateway | Partial — HTTP, no webhook verify |
| `telr` | TelrPaymentGateway | Partial — capture/refund incomplete |

---

## Feature flags (`config/ez-ecommerce.php`)

All default `true`. Disabling only stops gated code paths; tables still migrate.

| Flag | What actually works | What does NOT work yet |
|------|---------------------|------------------------|
| `api` | 15 REST endpoints | No auth on order endpoints; no customer CRUD |
| `subscriptions` | Create plan/subscription; renew dates | No charging, dunning, or API |
| `marketplace` | Commission rows on order | No vendor API, payouts |
| `multi_store` | `store_id` + `X-Commerce-Store` header | No store admin API |
| `b2b` | `net_terms` + company payment_terms on order | No company API, credit limits |
| `outbound_webhooks` | Signed HTTP POST on order events | Config URLs only; DB endpoints unused |

---

## Safe extension points

| Contract | Bind in | Purpose |
|----------|---------|---------|
| `PaymentGateway` | Host service provider | Custom PSP (implement all capability methods) |
| `PriceResolver` | `PricingServiceProvider` | Custom pricing rules |
| `TaxCalculator` | `TaxesServiceProvider` | Region-aware tax |
| `ShippingCalculator` | `ShippingServiceProvider` | Carrier rates |
| `CustomerResolver` | `CustomersServiceProvider` | Map auth user → Customer |
| `ReservationPolicy` | `InventoryServiceProvider` | TTL / commit rules |
| `StoreContext` | `StoresServiceProvider` | Multi-tenant store resolution |

**Not swappable via config:** Order, Payment, Cart, Inventory models.

---

## Common agent mistakes

1. **Adding `price()` to `Purchasable`** — rejected by design.
2. **Calling Stripe inside `DB::transaction`** — causes deadlocks and double charges.
3. **Using FQCN in `purchasable_type`** — breaks morph map; use aliases.
4. **Expecting `features.*` to add routes** — most flags only gate listeners/metadata.
5. **Using `Discounts\Actions\ApplyDiscountCode` in production cart flow** — `CartManager` uses `Cart\Actions\ApplyDiscountCode` (no date validation). Align them if you fix this.
6. **Assuming 17 tests = full coverage** — they cover the happy path only. See README test matrix.
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

Test harness: Orchestra Testbench, SQLite in-memory, `tests\TestCase.php` + `SetsUpCatalog` trait.

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
- Full Stripe/PayPal webhook verification infrastructure
- Subscription billing engine (Stripe Billing, etc.)
- Vendor payout automation
- Admin dashboard

---

## Origin note

This package was vibe-coded through long architecture sessions. Treat the design decisions above as intentional, not accidents. When in doubt: **smaller diff, boring code, one test.**
