<?php

use EzEcommerce\Core\Enums\SubscriptionInterval;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Discounts\Models\Discount;
use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Inventory\Models\InventoryBalance;
use EzEcommerce\Inventory\Models\Warehouse;
use EzEcommerce\Pricing\Contracts\PriceResolver;
use EzEcommerce\Pricing\Data\PricingContext;
use EzEcommerce\Pricing\Models\Price;
use EzEcommerce\Returns\Actions\CreateReturnRequest;
use EzEcommerce\Returns\Actions\ReceiveReturn;
use EzEcommerce\Returns\Actions\RestockReturnedItem;
use EzEcommerce\Subscriptions\Actions\CreateSubscription;
use EzEcommerce\Subscriptions\Models\SubscriptionPlan;
use EzEcommerce\Tests\Support\SetsUpCatalog;

uses(SetsUpCatalog::class);

it('applies percent discount code to cart', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000);

    Discount::query()->create([
        'code' => 'SAVE10',
        'type' => 'percent',
        'value' => 10,
        'is_active' => true,
    ]);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    EzEcommerce::cart()->applyDiscount($cart, 'SAVE10');
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');

    expect($cart->discount_total_minor)->toBeGreaterThan(0);
});

it('resolves price by precedence customer over base', function () {
    ['variant' => $variant, 'product' => $product] = $this->createProductWithVariant(priceMinor: 10000);

    $customer = Customer::query()->create(['email' => 'vip@example.com']);

    Price::query()->create([
        'priceable_type' => $variant->purchasableType(),
        'priceable_id' => $variant->id,
        'amount_minor' => 8000,
        'currency' => 'AED',
        'type' => 'customer',
        'customer_id' => $customer->id,
    ]);

    $quote = app(PriceResolver::class)->resolve($variant, new PricingContext(
        currency: 'AED',
        customer: $customer,
    ));

    expect($quote->source)->toBe('customer');
    expect($quote->unitPrice->minorAmount)->toBe(8000);
});

it('creates subscription for customer', function () {
    $customer = Customer::query()->create(['email' => 'sub@example.com']);
    $plan = SubscriptionPlan::query()->create([
        'name' => 'Monthly',
        'interval' => SubscriptionInterval::Month,
        'interval_count' => 1,
        'amount_minor' => 9900,
        'currency' => 'AED',
    ]);

    $subscription = app(CreateSubscription::class)->execute($customer, $plan);

    expect($subscription->status->value)->toBe('active');
    expect($subscription->current_period_end)->not->toBeNull();
});

it('processes return and restocks item', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 10);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');

    $result = placeCheckoutOrder($cart, 'return-flow-'.uniqid());

    $item = $result->order->items->first();
    $balance = InventoryBalance::query()
        ->where('stockable_id', $variant->id)
        ->first();

    $onHandBefore = $balance->on_hand;

    $return = app(CreateReturnRequest::class)->execute($result->order, [
        ['order_item_id' => $item->id, 'quantity' => 1, 'restock' => true],
    ], 'Customer return');

    app(ReceiveReturn::class)->execute($return);

    $warehouse = Warehouse::query()->first();
    app(RestockReturnedItem::class)->execute($return->items->first(), $warehouse, 'restock-'.uniqid());

    expect($balance->fresh()->on_hand)->toBe($onHandBefore + 1);
});

it('allocates money correctly', function () {
    $total = Money::fromMinor(1000, 'AED');
    $parts = [
        Money::fromMinor(700, 'AED'),
        Money::fromMinor(300, 'AED'),
    ];
    $allocated = Money::allocate($total, $parts);
    expect(array_sum(array_map(fn ($m) => $m->minorAmount, $allocated)))->toBe(1000);
});
