<?php

use EzEcommerce\Cart\Actions\ApplyDiscountCode;
use EzEcommerce\Cart\Actions\CalculateCartTotals;
use EzEcommerce\Discounts\Models\Discount;
use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Tests\Support\SetsUpCatalog;

uses(SetsUpCatalog::class);

it('taxes subtotal minus fixed discount when tax_after_discounts is enabled', function () {
    config()->set('ez-ecommerce.pricing.tax_after_discounts', true);
    config()->set('ez-ecommerce.pricing.shipping_taxable', false);
    config()->set('ez-ecommerce.tax.rate', 0.10);

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);

    Discount::query()->create([
        'code' => 'SAVE10',
        'type' => 'fixed',
        'value' => 1000,
        'is_active' => true,
    ]);

    $cart = app(ApplyDiscountCode::class)->execute($cart->fresh(), 'SAVE10');
    $cart = app(CalculateCartTotals::class)->execute($cart, 'flat');

    expect($cart->subtotal_minor)->toBe(10000);
    expect($cart->discount_total_minor)->toBe(1000);
    expect($cart->tax_total_minor)->toBe(900);
})->group('hardening');

it('taxes subtotal minus percentage discount', function () {
    config()->set('ez-ecommerce.pricing.tax_after_discounts', true);
    config()->set('ez-ecommerce.pricing.shipping_taxable', false);
    config()->set('ez-ecommerce.tax.rate', 0.10);

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);

    Discount::query()->create([
        'code' => 'TENOFF',
        'type' => 'percent',
        'value' => 10,
        'is_active' => true,
    ]);

    $cart = app(ApplyDiscountCode::class)->execute($cart->fresh(), 'TENOFF');
    $cart = app(CalculateCartTotals::class)->execute($cart, 'flat');

    expect($cart->tax_total_minor)->toBe(900);
})->group('hardening');

it('includes taxable shipping in tax base after discount', function () {
    config()->set('ez-ecommerce.pricing.tax_after_discounts', true);
    config()->set('ez-ecommerce.pricing.shipping_taxable', true);
    config()->set('ez-ecommerce.shipping.flat_rate_minor', 1000);
    config()->set('ez-ecommerce.tax.rate', 0.10);

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = app(CalculateCartTotals::class)->execute($cart, 'flat');

    expect($cart->tax_total_minor)->toBe(600);
})->group('hardening');

it('floors taxable base at zero when discount exceeds subtotal', function () {
    config()->set('ez-ecommerce.pricing.tax_after_discounts', true);
    config()->set('ez-ecommerce.pricing.shipping_taxable', false);
    config()->set('ez-ecommerce.tax.rate', 0.10);

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 1000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);

    Discount::query()->create([
        'code' => 'BIG',
        'type' => 'fixed',
        'value' => 5000,
        'is_active' => true,
    ]);

    $cart = app(ApplyDiscountCode::class)->execute($cart->fresh(), 'BIG');
    $cart = app(CalculateCartTotals::class)->execute($cart, 'flat');

    expect($cart->tax_total_minor)->toBe(0);
    expect($cart->grand_total_minor)->toBe(1000);
})->group('hardening');
