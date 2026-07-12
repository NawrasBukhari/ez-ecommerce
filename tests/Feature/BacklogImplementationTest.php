<?php

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Catalog\Models\Category;
use EzEcommerce\Catalog\Models\Product;
use EzEcommerce\CommerceManager;
use EzEcommerce\Core\Enums\CartStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\SubscriptionInterval;
use EzEcommerce\Core\Enums\VendorCommissionStatus;
use EzEcommerce\Core\Models\IdempotencyRecord;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Customers\Models\CustomerGroup;
use EzEcommerce\Marketplace\Models\Vendor;
use EzEcommerce\Marketplace\Models\VendorCommission;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Pricing\Models\Price;
use EzEcommerce\Pricing\Models\PriceList;
use EzEcommerce\Stores\Models\Store;
use EzEcommerce\Tests\Support\ResolvesCartApiIds;
use EzEcommerce\Tests\Support\SetsUpCatalog;
use EzEcommerce\Tests\Support\UsesCommerceApi;
use EzEcommerce\Webhooks\Inbound\Models\ProcessedGatewayEvent;
use EzEcommerce\Webhooks\Outbound\Models\WebhookDelivery;
use EzEcommerce\Webhooks\Outbound\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(SetsUpCatalog::class, UsesCommerceApi::class, ResolvesCartApiIds::class);

function backlogCheckout($test, $variant, string $key): array
{
    $guest = $test->postJson('/api/ez-commerce/v1/cart/guest', ['currency' => 'AED']);
    $token = $guest->json('guest_token');
    $cartId = $test->cartPublicIdFromResponse($guest);

    $test->withHeaders(['X-Guest-Cart-Token' => $token])
        ->postJson("/api/ez-commerce/v1/cart/{$cartId}/items", [
            'variant_id' => $variant->public_id,
            'quantity' => 1,
        ])->assertCreated();

    $calculate = $test->withHeaders(['X-Guest-Cart-Token' => $token])
        ->postJson("/api/ez-commerce/v1/cart/{$cartId}/calculate", ['shipping_method' => 'flat'])
        ->assertOk();

    $checkout = $test->withHeaders([
        'X-Guest-Cart-Token' => $token,
        'Idempotency-Key' => $key,
    ])->postJson('/api/ez-commerce/v1/checkout', [
        'cart_id' => $cartId,
        'shipping_method' => 'flat',
        'payment_method' => 'manual',
        'expected_totals_hash' => $calculate->json('totals_hash'),
    ]);

    return compact('token', 'cartId', 'checkout');
}

it('cancels unpaid order and lists transitions', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 5);
    ['checkout' => $checkout] = backlogCheckout($this, $variant, 'cancel-'.uniqid());
    $orderId = $checkout->json('order.id');
    $headers = $this->commerceApiHeaders();

    $this->withHeaders($headers)
        ->postJson("/api/ez-commerce/v1/orders/{$orderId}/cancel", ['reason' => 'Customer request'])
        ->assertOk()
        ->assertJsonPath('status', OrderStatus::Cancelled->value);

    $this->withHeaders($headers)
        ->getJson("/api/ez-commerce/v1/orders/{$orderId}/transitions")
        ->assertOk()
        ->assertJsonFragment(['to_state' => OrderStatus::Cancelled->value]);
});

it('lists vendor commissions and payouts', function () {
    $vendor = Vendor::query()->create(['name' => 'Read Vendor', 'slug' => 'read-vendor', 'commission_rate' => 0.1]);
    $customer = Customer::query()->create(['email' => 'commission@example.com']);
    $order = Order::query()->create([
        'customer_id' => $customer->id,
        'status' => OrderStatus::Confirmed,
        'payment_status' => 'paid',
        'fulfillment_status' => 'unfulfilled',
        'currency' => 'AED',
        'grand_total_minor' => 10000,
    ]);

    VendorCommission::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $order->items()->create([
            'name' => 'Item',
            'quantity' => 1,
            'unit_price_minor' => 10000,
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'price_source' => 'base',
            'price_quote_hash' => 'h',
            'priced_at' => now(),
            'product_snapshot' => [],
        ])->id,
        'vendor_id' => $vendor->id,
        'amount_minor' => 1000,
        'currency' => 'AED',
        'status' => VendorCommissionStatus::Pending,
    ]);

    $headers = $this->commerceApiHeaders();

    $this->withHeaders($headers)
        ->getJson("/api/ez-commerce/v1/vendors/{$vendor->public_id}/commissions")
        ->assertOk()
        ->assertJsonPath('data.0.status', VendorCommissionStatus::Pending->value);

    $this->withHeaders($headers)
        ->postJson("/api/ez-commerce/v1/vendors/{$vendor->public_id}/payouts")
        ->assertOk();

    $this->withHeaders($headers)
        ->getJson("/api/ez-commerce/v1/vendors/{$vendor->public_id}/payouts")
        ->assertOk()
        ->assertJsonPath('data.0.commission_count', 1);
});

it('creates and lists subscription plans via api', function () {
    $headers = $this->commerceApiHeaders();

    $create = $this->withHeaders($headers)
        ->postJson('/api/ez-commerce/v1/subscription-plans', [
            'name' => 'Pro',
            'interval' => SubscriptionInterval::Month->value,
            'amount_minor' => 9900,
            'currency' => 'AED',
        ])
        ->assertCreated();

    $planId = $create->json('id');

    $this->withHeaders($headers)
        ->getJson('/api/ez-commerce/v1/subscription-plans')
        ->assertOk();

    $this->withHeaders($headers)
        ->getJson("/api/ez-commerce/v1/subscription-plans/{$planId}")
        ->assertOk()
        ->assertJsonPath('name', 'Pro');
});

it('applies customer group price in cart totals', function () {
    $group = CustomerGroup::query()->create(['name' => 'VIP', 'code' => 'vip']);
    $customer = Customer::query()->create([
        'email' => 'vip@example.com',
        'customer_group_id' => $group->id,
    ]);

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);

    Price::query()->create([
        'priceable_type' => $variant->purchasableType(),
        'priceable_id' => $variant->id,
        'customer_group_id' => $group->id,
        'amount_minor' => 8000,
        'currency' => 'AED',
        'type' => 'customer_group',
    ]);

    $cart = Cart::query()->create([
        'customer_id' => $customer->id,
        'status' => CartStatus::Active,
        'currency' => 'AED',
        'version' => 0,
    ]);

    app(CommerceManager::class)->cart()->addItem($cart, $variant, 1);
    $cart = Cart::query()->findOrFail($cart->id);
    $cart = app(CommerceManager::class)->cart()->calculateTotals($cart, 'flat');

    expect($cart->items->first()->unit_price_minor)->toBe(8000);
});

it('lists categories and filters products', function () {
    $category = Category::query()->create(['name' => 'Shoes', 'slug' => 'shoes']);
    ['product' => $product] = $this->createProductWithVariant();
    $product->categories()->attach($category->id);

    $this->getJson('/api/ez-commerce/v1/categories')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'shoes');

    $this->getJson('/api/ez-commerce/v1/categories/'.$category->public_id.'/products')
        ->assertOk();

    $this->getJson('/api/ez-commerce/v1/products?category=shoes')
        ->assertOk();
});

it('rejects expired guest cart', function () {
    $cart = Cart::query()->create([
        'guest_token_hash' => hash('sha256', 'expired-token'),
        'status' => CartStatus::Active,
        'currency' => 'AED',
        'version' => 0,
        'expires_at' => now()->subDay(),
    ]);

    $this->withHeaders(['X-Guest-Cart-Token' => 'expired-token'])
        ->getJson('/api/ez-commerce/v1/cart/'.$cart->public_id)
        ->assertStatus(410);
});

it('purges expired carts via command', function () {
    Cart::query()->create([
        'guest_token_hash' => hash('sha256', 'old'),
        'status' => CartStatus::Active,
        'currency' => 'AED',
        'version' => 0,
        'expires_at' => now()->subDay(),
    ]);

    Artisan::call('commerce:purge-expired-carts');

    expect(Cart::query()->where('status', CartStatus::Expired)->count())->toBe(1);
});

it('scopes products by store header', function () {
    config()->set('ez-ecommerce.features.multi_store', true);

    $storeA = Store::query()->create(['name' => 'A', 'slug' => 'store-a', 'currency' => 'AED']);
    $storeB = Store::query()->create(['name' => 'B', 'slug' => 'store-b', 'currency' => 'AED']);

    $productA = Product::query()->create([
        'store_id' => $storeA->id,
        'name' => 'A Product',
        'slug' => 'a-product',
        'type' => 'physical',
    ]);
    Product::query()->create([
        'store_id' => $storeB->id,
        'name' => 'B Product',
        'slug' => 'b-product',
        'type' => 'physical',
    ]);

    $this->withHeaders(['X-Commerce-Store' => $storeA->public_id])
        ->getJson('/api/ez-commerce/v1/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $productA->public_id);
});

it('lists shipping methods', function () {
    $this->getJson('/api/ez-commerce/v1/shipping-methods')
        ->assertOk()
        ->assertJsonPath('0.code', 'flat');
});

it('lists inbound webhook events', function () {
    ProcessedGatewayEvent::query()->create([
        'gateway' => 'stripe',
        'external_event_id' => 'evt_1',
        'event_type' => 'payment_intent.succeeded',
        'payload' => ['id' => 'evt_1'],
        'processed_at' => now(),
    ]);

    $this->withHeaders($this->commerceApiHeaders())
        ->getJson('/api/ez-commerce/v1/inbound-webhooks/events?gateway=stripe')
        ->assertOk()
        ->assertJsonPath('data.0.gateway', 'stripe');
});

it('purges idempotency records via command', function () {
    IdempotencyRecord::query()->create([
        'scope' => 'test',
        'key' => 'k1',
        'request_hash' => 'hash',
        'status' => 'completed',
        'expires_at' => now()->subHour(),
    ]);

    Artisan::call('commerce:purge-idempotency-records');

    expect(IdempotencyRecord::query()->count())->toBe(0);
});

it('retries failed webhook delivery', function () {
    Queue::fake();

    $endpoint = WebhookEndpoint::query()->create([
        'url' => 'https://example.com/hook',
        'secret' => 'secret',
        'events' => ['order.placed'],
        'active' => true,
    ]);

    $delivery = WebhookDelivery::query()->create([
        'endpoint_id' => $endpoint->id,
        'event' => 'order.placed',
        'payload' => ['order_id' => 1],
        'status' => 'failed',
    ]);

    $this->withHeaders($this->commerceApiHeaders())
        ->postJson("/api/ez-commerce/v1/webhook-deliveries/{$delivery->id}/retry")
        ->assertOk()
        ->assertJsonPath('status', 'queued');
});

it('reads order fulfillments refunds and payments', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 5);
    ['checkout' => $checkout] = backlogCheckout($this, $variant, 'reads-'.uniqid());
    $orderId = $checkout->json('order.id');
    $headers = $this->commerceApiHeaders();

    $this->withHeaders($headers)->getJson("/api/ez-commerce/v1/orders/{$orderId}/payments")->assertOk();
    $this->withHeaders($headers)->getJson("/api/ez-commerce/v1/orders/{$orderId}/refunds")->assertOk();
    $this->withHeaders($headers)->getJson("/api/ez-commerce/v1/orders/{$orderId}/fulfillments")->assertOk();
});

it('uses price list from cart calculate', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    $list = PriceList::query()->create(['name' => 'Wholesale', 'code' => 'wholesale', 'currency' => 'AED']);

    Price::query()->create([
        'priceable_type' => $variant->purchasableType(),
        'priceable_id' => $variant->id,
        'price_list_id' => $list->id,
        'amount_minor' => 7500,
        'currency' => 'AED',
        'type' => 'price_list',
    ]);

    $guest = $this->postJson('/api/ez-commerce/v1/cart/guest', ['currency' => 'AED']);
    $token = $guest->json('guest_token');
    $cartId = $this->cartPublicIdFromResponse($guest);

    $this->withHeaders(['X-Guest-Cart-Token' => $token])
        ->postJson("/api/ez-commerce/v1/cart/{$cartId}/items", [
            'variant_id' => $variant->public_id,
            'quantity' => 1,
        ]);

    $this->withHeaders(['X-Guest-Cart-Token' => $token])
        ->postJson("/api/ez-commerce/v1/cart/{$cartId}/calculate", [
            'shipping_method' => 'flat',
            'price_list_id' => $list->public_id,
        ])
        ->assertOk()
        ->assertJsonPath('data.items.0.unit_price_minor', 7500);
});
