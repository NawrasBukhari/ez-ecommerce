# Changelog

All notable changes to `ez-ecommerce/ez-ecommerce` are documented in this file.

## Unreleased

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
- 44 package migrations (`commerce_*` tables).
- 42 Pest tests across 8 feature files.

### Security

- Fail-closed API token middleware (`503` when token unset).
- Checkout cart access validation middleware.
- Gateway-specific payment capture allowlist in `ReconcilePayment`.
- Boolean env parsing for `allow_unauthenticated` / `allow_unsigned`.

### Changed

- All `features.*` flags default to `true` for day-one readiness.
- Package migrations load automatically; host app runs `php artisan migrate`.
- Telr driver implements refund HTTP call (capture remains optimistic).
- Address model uses `country_code` column (API accepts `country`).
