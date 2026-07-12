<?php

use EzEcommerce\B2B\Models\Company;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Catalog\Models\Product;
use EzEcommerce\Catalog\Models\ProductVariant;
use EzEcommerce\Core\Enums\CartStatus;
use EzEcommerce\Core\Enums\FulfillmentStatus;
use EzEcommerce\Core\Enums\OrderPaymentStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\SubscriptionInterval;
use EzEcommerce\Core\Enums\VendorCommissionStatus;
use EzEcommerce\Core\Support\MorphMap;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Marketplace\Actions\PayVendorCommissions;
use EzEcommerce\Marketplace\Models\Vendor;
use EzEcommerce\Marketplace\Models\VendorCommission;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderItem;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Pricing\Models\Price;
use EzEcommerce\Returns\Models\ReturnItem;
use EzEcommerce\Returns\Models\ReturnRequest;
use EzEcommerce\Stores\Models\Store;
use EzEcommerce\Subscriptions\Models\Subscription;
use EzEcommerce\Subscriptions\Models\SubscriptionItem;
use EzEcommerce\Subscriptions\Models\SubscriptionPlan;
use EzEcommerce\Tests\Support\SetsUpCatalog;
use EzEcommerce\Webhooks\Outbound\Models\WebhookDelivery;
use EzEcommerce\Webhooks\Outbound\Models\WebhookEndpoint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(SetsUpCatalog::class);

const COMMERCE_TABLES = [
    'commerce_addresses',
    'commerce_cart_adjustments',
    'commerce_cart_items',
    'commerce_carts',
    'commerce_categories',
    'commerce_category_product',
    'commerce_companies',
    'commerce_customer_groups',
    'commerce_customers',
    'commerce_discounts',
    'commerce_fulfillments',
    'commerce_idempotency_records',
    'commerce_inbound_webhooks',
    'commerce_inventory_balances',
    'commerce_inventory_movements',
    'commerce_inventory_reservations',
    'commerce_order_adjustments',
    'commerce_order_items',
    'commerce_order_transitions',
    'commerce_orders',
    'commerce_outbox_messages',
    'commerce_payment_attempts',
    'commerce_payment_transactions',
    'commerce_payments',
    'commerce_price_lists',
    'commerce_prices',
    'commerce_processed_gateway_events',
    'commerce_product_variants',
    'commerce_products',
    'commerce_refunds',
    'commerce_return_items',
    'commerce_returns',
    'commerce_stores',
    'commerce_subscription_items',
    'commerce_subscription_plans',
    'commerce_subscriptions',
    'commerce_vendor_commissions',
    'commerce_vendor_payouts',
    'commerce_vendors',
    'commerce_warehouses',
    'commerce_webhook_deliveries',
    'commerce_webhook_endpoints',
];

it('creates all commerce tables from migrations', function () {
    $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'commerce_%'"))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    expect($tables)->toBe(COMMERCE_TABLES);
});

it('adds expected columns on altered tables', function () {
    expect(Schema::hasColumn('commerce_products', 'store_id'))->toBeTrue()
        ->and(Schema::hasColumn('commerce_products', 'vendor_id'))->toBeTrue()
        ->and(Schema::hasColumn('commerce_customers', 'company_id'))->toBeTrue()
        ->and(Schema::hasColumn('commerce_vendor_commissions', 'payout_id'))->toBeTrue()
        ->and(Schema::hasColumn('commerce_addresses', 'country_code'))->toBeTrue();
});

it('assigns public_id on models that use it', function () {
    $customer = Customer::query()->create(['email' => 'public-id@example.com']);
    $store = Store::query()->create(['name' => 'Main', 'slug' => 'main-'.uniqid(), 'currency' => 'AED']);
    $vendor = Vendor::query()->create(['name' => 'V', 'slug' => 'v-'.uniqid(), 'commission_rate' => 0.1]);

    expect($customer->public_id)->not->toBeEmpty()
        ->and($store->public_id)->not->toBeEmpty()
        ->and($vendor->public_id)->not->toBeEmpty();
});

it('resolves catalog and pricing relations', function () {
    ['product' => $product, 'variant' => $variant] = $this->createProductWithVariant();

    $product->load('variants');
    expect($product->variants)->toHaveCount(1)
        ->and($product->variants->first()->is($variant))->toBeTrue();

    $variant->load('product');
    expect($variant->product->is($product))->toBeTrue();

    $price = Price::query()->where('priceable_id', $variant->id)->first();
    $price->load('priceable');
    expect($price->priceable)->toBeInstanceOf(ProductVariant::class)
        ->and($price->priceable_type)->toBe(ProductVariant::MORPH_ALIAS);
});

it('resolves customer address and company relations', function () {
    $company = Company::query()->create(['name' => 'Acme']);
    $customer = Customer::query()->create([
        'email' => 'relations@example.com',
        'company_id' => $company->id,
    ]);
    $address = $customer->addresses()->create([
        'type' => 'shipping',
        'line1' => '1 Main St',
        'city' => 'Dubai',
        'country_code' => 'AE',
    ]);

    $customer->load(['company', 'addresses']);
    expect($customer->company->is($company))->toBeTrue()
        ->and($customer->addresses)->toHaveCount(1)
        ->and($customer->addresses->first()->is($address))->toBeTrue();

    $address->load('customer');
    expect($address->customer->is($customer))->toBeTrue();
});

it('resolves cart item morph to variant via morph alias', function () {
    ['variant' => $variant] = $this->createProductWithVariant();
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    $item = EzEcommerce::cart()->addItem($cart, $variant, 2);

    expect($item->purchasable_type)->toBe(ProductVariant::MORPH_ALIAS);

    $item->load('cart', 'purchasable');
    expect($item->cart->is($cart))->toBeTrue()
        ->and($item->purchasable)->toBeInstanceOf(ProductVariant::class)
        ->and($item->purchasable->is($variant))->toBeTrue();
});

it('resolves order payment and item relations after checkout', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');

    $result = EzEcommerce::checkout()->for($cart)
        ->shippingMethod('flat')
        ->paymentMethod('manual')
        ->place(idempotencyKey: 'relations-'.uniqid());

    $order = Order::query()->with(['items', 'payments.attempts', 'cart', 'customer'])->findOrFail($result->order->id);

    expect($order->cart_id)->toBe($cart->id)
        ->and($order->items)->toHaveCount(1)
        ->and($order->payments)->toHaveCount(1);

    $payment = $order->payments->first();
    expect($payment->order->is($order))->toBeTrue()
        ->and($payment->attempts)->not->toBeEmpty();

    $order->load('cart');
    expect($order->cart->is($cart))->toBeTrue();
});

it('resolves marketplace vendor payout relations', function () {
    $vendor = Vendor::query()->create([
        'name' => 'Relation Vendor',
        'slug' => 'relation-vendor',
        'commission_rate' => 0.15,
    ]);

    $customer = Customer::query()->create(['email' => 'vendor-order@example.com']);
    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'status' => OrderStatus::Confirmed,
        'payment_status' => OrderPaymentStatus::Paid,
        'fulfillment_status' => FulfillmentStatus::Unfulfilled,
        'currency' => 'AED',
        'grand_total_minor' => 10000,
    ]);

    $orderItem = OrderItem::query()->create([
        'order_id' => $order->id,
        'name' => 'Item',
        'sku' => 'SKU-1',
        'quantity' => 1,
        'unit_price_minor' => 10000,
        'subtotal_minor' => 10000,
        'total_minor' => 10000,
        'price_source' => 'base',
        'price_quote_hash' => 'hash',
        'priced_at' => now(),
        'product_snapshot' => ['vendor_id' => $vendor->id],
    ]);

    $commission = VendorCommission::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'vendor_id' => $vendor->id,
        'amount_minor' => 1500,
        'currency' => 'AED',
        'status' => VendorCommissionStatus::Pending,
    ]);

    $result = app(PayVendorCommissions::class)->execute($vendor);
    $payout = $result['payout']->load(['vendor', 'commissions']);

    expect($payout->vendor->is($vendor))->toBeTrue()
        ->and($payout->commissions)->toHaveCount(1)
        ->and($payout->commissions->first()->is($commission))->toBeTrue();

    $commission->refresh()->load('payout');
    expect($commission->payout->is($payout))->toBeTrue()
        ->and($commission->status)->toBe(VendorCommissionStatus::Paid);
});

it('resolves return request item relations', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 3000, stock: 3);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = EzEcommerce::checkout()->for($cart)
        ->shippingMethod('flat')
        ->paymentMethod('manual')
        ->place(idempotencyKey: 'return-rel-'.uniqid());

    $orderItem = $result->order->items->first();
    $return = ReturnRequest::query()->create([
        'order_id' => $result->order->id,
        'customer_id' => $result->order->customer_id,
        'status' => 'requested',
        'reason' => 'Test',
    ]);
    $returnItem = ReturnItem::query()->create([
        'return_id' => $return->id,
        'order_item_id' => $orderItem->id,
        'quantity' => 1,
    ]);

    $return->load(['order', 'items.orderItem']);
    expect($return->order->is($result->order))->toBeTrue()
        ->and($return->items)->toHaveCount(1)
        ->and($return->items->first()->orderItem->is($orderItem))->toBeTrue();
});

it('resolves subscription plan item morph relations', function () {
    $customer = Customer::query()->create(['email' => 'sub@example.com']);
    $plan = SubscriptionPlan::query()->create([
        'name' => 'Monthly',
        'interval' => SubscriptionInterval::Month,
        'interval_count' => 1,
        'amount_minor' => 9900,
        'currency' => 'AED',
    ]);
    $subscription = Subscription::query()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'currency' => 'AED',
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);
    ['variant' => $variant] = $this->createProductWithVariant();
    SubscriptionItem::query()->create([
        'subscription_id' => $subscription->id,
        'purchasable_type' => ProductVariant::MORPH_ALIAS,
        'purchasable_id' => $variant->id,
        'quantity' => 1,
    ]);

    $subscription->load(['customer', 'plan', 'items.purchasable']);
    expect($subscription->customer->is($customer))->toBeTrue()
        ->and($subscription->plan->is($plan))->toBeTrue()
        ->and($subscription->items)->toHaveCount(1)
        ->and($subscription->items->first()->purchasable)->toBeInstanceOf(ProductVariant::class);

    $plan->load('subscriptions');
    expect($plan->subscriptions->contains(fn ($s) => $s->is($subscription)))->toBeTrue();
});

it('resolves webhook endpoint delivery relations', function () {
    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://example.com/hook',
        'secret' => 'whsec_test',
        'events' => ['order.placed'],
        'active' => true,
    ]);
    $delivery = WebhookDelivery::query()->create([
        'endpoint_id' => $endpoint->id,
        'event' => 'order.placed',
        'payload' => ['order_id' => 1],
        'status' => 'pending',
    ]);

    $endpoint->load('deliveries');
    expect($endpoint->deliveries->first()->is($delivery))->toBeTrue();

    $delivery->load('endpoint');
    expect($delivery->endpoint->is($endpoint))->toBeTrue();
});

it('registers default morph aliases', function () {
    expect(MorphMap::has(Product::MORPH_ALIAS))->toBeTrue()
        ->and(MorphMap::has(ProductVariant::MORPH_ALIAS))->toBeTrue()
        ->and(MorphMap::classFor(ProductVariant::MORPH_ALIAS))->toBe(ProductVariant::class);
});

it('enforces foreign keys on customer cart relation', function () {
    expect(fn () => Cart::query()->create([
        'customer_id' => 999999,
        'status' => CartStatus::Active,
        'currency' => 'AED',
        'version' => 0,
    ]))->toThrow(QueryException::class);
});

it('cascades customer cart relation from customer model', function () {
    $customer = Customer::query()->create(['email' => 'cascade@example.com']);
    $cart = Cart::query()->create([
        'customer_id' => $customer->id,
        'status' => CartStatus::Active,
        'currency' => 'AED',
        'version' => 0,
    ]);

    $customer->load('carts');
    expect($customer->carts->contains(fn (Cart $c) => $c->is($cart)))->toBeTrue();
});

it('links payment attempt and transaction relations', function () {
    $customer = Customer::query()->create(['email' => 'payment-rel@example.com']);
    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'status' => OrderStatus::Confirmed,
        'payment_status' => OrderPaymentStatus::Unpaid,
        'fulfillment_status' => FulfillmentStatus::Unfulfilled,
        'currency' => 'AED',
        'grand_total_minor' => 1000,
    ]);
    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'gateway' => 'manual',
        'status' => 'pending',
        'amount_minor' => 1000,
        'currency' => 'AED',
    ]);
    $attempt = PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'session',
        'idempotency_key' => 'attempt-'.uniqid(),
        'status' => 'pending',
    ]);

    $payment->load(['order', 'attempts']);
    expect($payment->order->is($order))->toBeTrue()
        ->and($payment->attempts->first()->is($attempt))->toBeTrue();

    $attempt->load('payment');
    expect($attempt->payment->is($payment))->toBeTrue();
});
