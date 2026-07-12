# ez-ecommerce

A modular, headless e-commerce engine for Laravel.

## Installation

```bash
composer require ez-ecommerce/ez-ecommerce
```

```bash
php artisan commerce:install
php artisan migrate
```

`commerce:install` publishes config and translations. Migrations load from the package automatically.

## Quick start

```php
use EzEcommerce\Facades\EzEcommerce;

['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
EzEcommerce::cart()->addItem($cart, $variant, 2);
$cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');

$result = EzEcommerce::checkout()->for($cart)
    ->shippingMethod('flat')
    ->paymentMethod('manual')
    ->place(
        idempotencyKey: $request->header('Idempotency-Key'),
        expectedTotalsHash: EzEcommerce::cart()->totalsHash($cart, 'flat'),
    );

$order = $result->order;
$payment = $result->payment;
```

## Architecture

- **Headless** — no UI framework coupling
- **Module providers** — Catalog, Pricing, Inventory, Cart, Checkout, Orders, Payments, etc.
- **Contracts & actions** — replaceable payment, shipping, tax, pricing, and inventory drivers
- **Integer money** — minor units via `brick/money`
- **Order snapshots** — immutable line items and adjustments
- **Idempotent checkout** — safe retries with `Idempotency-Key`

## Commands

```bash
php artisan commerce:install
php artisan commerce:release-expired-reservations
```

## Configuration

Publish with `php artisan vendor:publish --tag="ez-ecommerce-config"`.

Key settings in `config/ez-ecommerce.php`:

- `currency.default`
- `inventory.default_warehouse_id`
- `drivers.payment` / `shipping` / `tax`
- `features.*` — advanced modules (API, subscriptions, marketplace) default to `false`

## Testing

```bash
composer test
```

## License

MIT
