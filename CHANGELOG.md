# Changelog

All notable changes to `ez-ecommerce/ez-ecommerce` are documented in this file.

## Unreleased

### Added

- Headless commerce engine for Laravel 11–13 with modular providers (Catalog, Pricing, Inventory, Cart, Checkout, Orders, Payments, Fulfillment, Refunds, Returns, Discounts, Subscriptions, Marketplace, B2B, Stores, Webhooks).
- REST API at `api/ez-commerce/v1` (products, guest cart, checkout, orders).
- Payment drivers: manual, null, fake, Stripe, PayPal, Telr (optional SDKs via Composer suggest).
- Idempotent checkout with `Idempotency-Key` header support.
- Integer money handling via `brick/money`.
- Order snapshots, transitions, and status projections.
- Inventory reservations with signed movements and expiry release command.
- Discount codes with cart adjustments.
- Returns workflow: request, receive, restock, mark damaged.
- Subscriptions with plan billing periods and renewal command.
- Marketplace vendor commissions on order placement.
- Multi-store context via `X-Commerce-Store` header or default store config.
- B2B net terms checkout (`payment_method: net_terms`) with company payment terms metadata.
- Outbound webhooks with signed delivery jobs.
- Inbound PSP webhook processing tables.
- Artisan commands: `commerce:install`, `commerce:release-expired-reservations`, `commerce:renew-subscriptions`.
- 44 package migrations (`commerce_*` tables).
- Pest test suite covering core flow, hardening, modules, and API.

### Changed

- All `features.*` flags default to `true` for day-one readiness.
- Package migrations load automatically; host app runs `php artisan migrate`.
