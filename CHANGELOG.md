# Changelog

All notable changes to `ez-ecommerce/ez-ecommerce` are documented in this file.

## Unreleased

### Added (production hardening sprint)

- Required `Idempotency-Key` on API `cancel`, `complete`, and `fulfill` order endpoints.
- `checkout.public_payment_methods` config — public checkout rejects disallowed payment methods (default: stripe, paypal, telr).
- `PriceListEligibility` contract + `DefaultPriceListEligibility`; validates `price_list_id` on calculate/checkout.
- Address-aware cart quote: `POST /cart/{id}/calculate` accepts `shipping_address`; `totals_hash` includes address + price list.
- `ReconcileRefund` action + inbound refund webhook routing.
- PostgreSQL CI hardening job (`postgres-hardening`).
- `RegistersCommerceApiExceptions` — maps version/totals/idempotency conflicts to 409/422.
- Stripe partial capture policy (`allow_partial_capture` + `final_capture`).
- `order_paid_dispatched` metadata guard against duplicate `OrderPaid` events.
- Outbound `DeliverWebhookJob` throws on non-2xx responses.
- `commerce:reconcile-payments --list-stale` for operator visibility.
- Cart version atomic bumps via `BumpsCartVersionAtomically` trait.
- 102 Pest tests across 13 feature files.

### Fixed (production hardening sprint)

- Post-capture webhook idempotency (`ApplyPaymentCapture` duplicate/fully-captured ordering).
- Stripe ledger refs normalized (`ch_*` capture refs; refunds use charge or payment_intent).
- `IdempotencyStore` PostgreSQL `UniqueConstraintViolationException` recovery outside transaction.
- `RetryPaymentSession` reuses keys and blocks unsafe pending retries.
- Pending Stripe refunds no longer marked failed immediately.
- Telr capture rejected before PSP call when gateway lacks capture capability.

### Added

- Headless commerce engine for Laravel 11–13 with modular providers (Catalog, Pricing, Inventory, Cart, Checkout, Orders, Payments, Fulfillment, Refunds, Returns, Discounts, Subscriptions, Marketplace, B2B, Stores, Webhooks).
- REST API at `api/ez-commerce/v1` (products, guest cart, checkout, orders, returns, customers, addresses, stores, companies, vendors, subscriptions, cart merge, retry payment).
- API authentication via `COMMERCE_API_TOKEN` (fail-closed when unset).
- Guest cart + checkout protection via `X-Guest-Cart-Token`.
- Inbound webhooks at `POST /webhooks/{gateway}` with Stripe signature and shared-secret auth for PayPal/Telr.
- Payment drivers: manual, null, fake, Stripe, PayPal, Telr (optional SDKs via Composer suggest).
- Idempotent checkout with `Idempotency-Key` header support.
- Integer money handling via `brick/money`.
- Order snapshots, transitions, and status projections.
- Inventory reservations with signed movements and expiry release command.
- Discount codes with cart adjustments and `removeDiscount` API.
- Returns workflow: request, receive, restock, mark damaged (manager + API).
- Subscriptions with plan billing periods, `BillSubscriptionPeriod`, and renewal command.
- Marketplace vendor commissions on order placement + vendor API.
- Multi-store context via `X-Commerce-Store` header or default store config + stores API.
- B2B net terms checkout (`payment_method: net_terms`) with companies API.
- Outbound webhooks with outbox, signed delivery jobs, and endpoint tracking.
- Artisan commands: `commerce:install`, `commerce:release-expired-reservations`, `commerce:renew-subscriptions`.
- 46 package migrations (`commerce_*` tables).
- 52 Pest tests across 9 feature files.

### Security

- Fail-closed API token middleware (`503` when no tokens configured).
- Per-route API scopes via `api.scoped_tokens`.
- Checkout cart access validation middleware.
- Gateway-specific payment capture allowlist in `ReconcilePayment`.
- PayPal native webhook signature verification when `PAYPAL_WEBHOOK_ID` is set.
- Boolean env parsing for `allow_unauthenticated` / `allow_unsigned`.

### Added (reconciliation & hardening)

- Operator commands: `commerce:reconcile-payments`, `commerce:reconcile-refunds`, `commerce:reconcile-finalizations` for unknown PSP attempts and post-capture finalization recovery.
- Capture/refund idempotency keys on Stripe and PayPal; unknown attempts block conflicting retries until reconciled.
- `CorrectnessHardeningTest` suite (`--group=hardening`) for payment correctness on MySQL.
- `testbench.yaml` for Larastan/static analysis in package development.


- `POST /customers/{id}/cart`, catalog write, inventory admin, vendor payouts API.
- `commerce_vendor_payouts` table + `PayVendorCommissions` action.
- Scoped API tokens (`catalog`, `inventory`, `orders`, `customers`, `marketplace`, etc.).

### Added (backlog implementation)

- Order cancel/complete API + order transitions, fulfillments, refunds, payments read endpoints.
- Marketplace commission + payout history read APIs.
- Subscription plans API, customer groups API, public categories + product filters.
- Inventory transfer/adjust/deactivate, movements read, reservation release.
- Cart expiry enforcement + purge command; `price_list_id` on cart calculate/checkout.
- `GET /shipping-methods`, weight shipping calculator, jurisdiction tax driver.
- Webhook delivery retry, outbound events (`return.received`, `refund.created`, `subscription.renewed`), inbound event log API.
- Migrations: `customer_group_id` on customers, `metadata` on carts.
- 81 Pest tests across 11 feature files.

### Changed (correctness consolidation)

- `IdempotencyStore` uses short DB transactions only; gateway calls run outside any open transaction.
- Tax after discount subtracts from taxable base (was incorrectly added).
- Checkout resolves customer before final cart recalculation; order lines reuse cart item prices.
- Order customer/address snapshots (`commerce_order_addresses`, customer email/name/phone on order).
- `CapturePayment` / `RefundPayment` lock payments and validate remaining balances; ledger-driven aggregates.
- `ConfirmOrderOnPaymentAccepted` on manual capture and webhook reconcile.
- Fulfillment validates remaining quantity per line with row lock.
- Webhook reconcile uses inbox `status` on processed events; skips capture when payment not found.
- `PaymentGatewayRegistry` replaces duplicated gateway resolution.
- Advanced feature flags (`subscriptions`, `marketplace`, `b2b`, `outbound_webhooks`) default to `false`.
- `POST /checkout` requires `expected_totals_hash`; cart calculate returns `totals_hash`.
- 89 Pest tests; MySQL hardening job uses real MySQL connection.

### Changed

- `DefaultStoreContext` resolves store per request (no stale cache).
- `DeliverWebhookJob` retries with backoff (3 attempts).
- Package migrations load automatically; host app runs `php artisan migrate`.
- Telr driver implements refund HTTP call (capture remains optimistic).
- Address model uses `country_code` column (API accepts `country`).
