<?php

use EzEcommerce\Core\Enums\VendorCommissionStatus;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Inventory\Models\InventoryBalance;
use EzEcommerce\Marketplace\Models\Vendor;
use EzEcommerce\Marketplace\Models\VendorCommission;
use EzEcommerce\Tests\Support\SetsUpCatalog;
use EzEcommerce\Tests\Support\UsesCommerceApi;

uses(SetsUpCatalog::class, UsesCommerceApi::class);

it('creates customer cart via api', function () {
    $customer = Customer::query()->create(['email' => 'cart-owner@example.com']);

    $cart = $this->withHeaders($this->commerceApiHeaders())
        ->postJson("/api/ez-commerce/v1/customers/{$customer->public_id}/cart", ['currency' => 'AED'])
        ->assertCreated()
        ->json();

    expect($cart['id'])->not->toBeNull()
        ->and($cart['currency'])->toBe('AED');

    $again = $this->withHeaders($this->commerceApiHeaders())
        ->postJson("/api/ez-commerce/v1/customers/{$customer->public_id}/cart", ['currency' => 'AED'])
        ->assertCreated()
        ->json();

    expect($again['id'])->toBe($cart['id']);
});

it('creates product via catalog write api', function () {
    $product = $this->withHeaders($this->commerceApiHeaders())
        ->postJson('/api/ez-commerce/v1/products', [
            'name' => 'API Product',
            'type' => 'physical',
            'stock' => 5,
            'variant' => [
                'sku' => 'API-SKU-'.uniqid(),
                'name' => 'Default',
                'price_minor' => 2500,
                'currency' => 'AED',
            ],
        ])
        ->assertCreated()
        ->json();

    expect($product['name'])->toBe('API Product')
        ->and($product['variants'])->toHaveCount(1);
});

it('receives inventory via admin api', function () {
    ['variant' => $variant, 'warehouse' => $warehouse] = $this->createProductWithVariant(stock: 1);

    $balance = $this->withHeaders($this->commerceApiHeaders())
        ->postJson("/api/ez-commerce/v1/warehouses/{$warehouse->public_id}/receive", [
            'variant_id' => $variant->public_id,
            'quantity' => 3,
            'idempotency_key' => 'receive-'.uniqid(),
        ])
        ->assertOk()
        ->json();

    expect($balance['on_hand'])->toBe(4);

    $listed = $this->withHeaders($this->commerceApiHeaders())
        ->getJson('/api/ez-commerce/v1/warehouses')
        ->assertOk()
        ->json('data');

    expect($listed)->not->toBeEmpty();
});

it('pays vendor commissions via marketplace payout api', function () {
    $vendor = Vendor::query()->create([
        'name' => 'Payout Vendor',
        'slug' => 'payout-vendor',
        'commission_rate' => 0.1,
    ]);

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    $variant->product->update(['vendor_id' => $vendor->id]);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');

    EzEcommerce::checkout()->for($cart)
        ->shippingMethod('flat')
        ->paymentMethod('manual')
        ->place(idempotencyKey: 'payout-'.uniqid());

    $commission = VendorCommission::query()
        ->where('vendor_id', $vendor->id)
        ->where('status', VendorCommissionStatus::Pending)
        ->first();

    expect($commission)->not->toBeNull();

    $payout = $this->withHeaders($this->commerceApiHeaders())
        ->postJson("/api/ez-commerce/v1/vendors/{$vendor->public_id}/payouts")
        ->assertOk()
        ->json();

    expect($payout['amount_minor'])->toBe($commission->amount_minor)
        ->and($payout['commission_count'])->toBe(1)
        ->and($commission->fresh()->status)->toBe(VendorCommissionStatus::Paid);
});

it('scoped catalog token cannot access inventory routes', function () {
    config()->set('ez-ecommerce.api.scoped_tokens', [
        'catalog-token' => ['catalog.read', 'catalog.write'],
    ]);

    $this->withHeaders(['Authorization' => 'Bearer catalog-token'])
        ->getJson('/api/ez-commerce/v1/warehouses')
        ->assertForbidden();
});

it('scoped inventory token can receive stock', function () {
    config()->set('ez-ecommerce.api.scoped_tokens', [
        'inventory-token' => ['inventory.read', 'inventory.write'],
    ]);

    ['variant' => $variant, 'warehouse' => $warehouse] = $this->createProductWithVariant(stock: 2);

    $this->withHeaders(['Authorization' => 'Bearer inventory-token'])
        ->postJson("/api/ez-commerce/v1/warehouses/{$warehouse->public_id}/receive", [
            'variant_id' => $variant->public_id,
            'quantity' => 1,
            'idempotency_key' => 'scoped-receive-'.uniqid(),
        ])
        ->assertOk();

    expect(InventoryBalance::query()
        ->where('warehouse_id', $warehouse->id)
        ->where('stockable_id', $variant->id)
        ->value('on_hand'))->toBe(3);
});
