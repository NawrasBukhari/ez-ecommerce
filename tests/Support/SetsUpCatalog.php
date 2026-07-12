<?php

namespace EzEcommerce\Tests\Support;

use EzEcommerce\Catalog\Models\Product;
use EzEcommerce\Catalog\Models\ProductVariant;
use EzEcommerce\Inventory\Models\InventoryBalance;
use EzEcommerce\Inventory\Models\Warehouse;
use EzEcommerce\Pricing\Models\Price;

trait SetsUpCatalog
{
    protected function createProductWithVariant(
        int $priceMinor = 10000,
        string $currency = 'AED',
        int $stock = 10,
    ): array {
        $warehouse = Warehouse::query()->create(['name' => 'Default', 'code' => 'DEFAULT']);
        config()->set('ez-ecommerce.inventory.default_warehouse_id', $warehouse->id);

        $product = Product::query()->create([
            'name' => 'Test Product',
            'slug' => 'test-product-'.uniqid(),
            'type' => 'physical',
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.uniqid(),
            'name' => 'Default',
        ]);

        Price::query()->create([
            'priceable_type' => ProductVariant::MORPH_ALIAS,
            'priceable_id' => $variant->id,
            'amount_minor' => $priceMinor,
            'currency' => $currency,
            'type' => 'base',
        ]);

        InventoryBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'stockable_type' => ProductVariant::MORPH_ALIAS,
            'stockable_id' => $variant->id,
            'on_hand' => $stock,
            'reserved' => 0,
        ]);

        return compact('product', 'variant', 'warehouse');
    }
}
