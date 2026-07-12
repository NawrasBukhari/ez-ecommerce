<?php

namespace EzEcommerce\Catalog\Actions;

use EzEcommerce\Catalog\Models\Category;
use EzEcommerce\Catalog\Models\Product;
use EzEcommerce\Catalog\Models\ProductVariant;
use EzEcommerce\CommerceManager;
use EzEcommerce\Inventory\Models\Warehouse;
use EzEcommerce\Marketplace\Models\Vendor;
use EzEcommerce\Pricing\Models\Price;
use EzEcommerce\Stores\Contracts\StoreContext;
use Illuminate\Support\Str;

final class CreateProductWithVariant
{
    public function __construct(
        private readonly CommerceManager $commerce,
        private readonly StoreContext $storeContext,
    ) {}

    /**
     * @param  array{name: string, slug?: string|null, type?: string, description?: string|null, vendor_id?: string|null, variant: array{sku: string, name: string, price_minor: int, currency?: string}, stock?: int}  $data
     */
    public function execute(array $data): Product
    {
        $vendorId = null;
        if (! empty($data['vendor_id'])) {
            $vendorId = Vendor::query()
                ->where('public_id', $data['vendor_id'])
                ->value('id');
        }

        $product = Product::query()->create([
            'store_id' => $this->storeContext->id(),
            'vendor_id' => $vendorId,
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']).'-'.Str::lower(Str::random(6)),
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? 'physical',
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => $data['variant']['sku'],
            'name' => $data['variant']['name'],
        ]);

        $currency = strtoupper($data['variant']['currency'] ?? config('ez-ecommerce.currency.default', 'AED'));

        Price::query()->create([
            'priceable_type' => ProductVariant::MORPH_ALIAS,
            'priceable_id' => $variant->id,
            'amount_minor' => $data['variant']['price_minor'],
            'currency' => $currency,
            'type' => 'base',
        ]);

        if (! empty($data['variant']['sale_price_minor'])) {
            Price::query()->create([
                'priceable_type' => ProductVariant::MORPH_ALIAS,
                'priceable_id' => $variant->id,
                'amount_minor' => $data['variant']['sale_price_minor'],
                'currency' => $currency,
                'type' => 'sale',
            ]);
        }

        if (! empty($data['category_ids'])) {
            $categoryIds = Category::query()
                ->whereIn('public_id', $data['category_ids'])
                ->pluck('id');
            $product->categories()->sync($categoryIds);
        }

        $stock = (int) ($data['stock'] ?? 0);
        if ($stock > 0) {
            $warehouse = $this->resolveWarehouse();
            $this->commerce->inventory()->receiveStock(
                $warehouse,
                $variant,
                $stock,
                'catalog-create-'.$variant->public_id,
            );
        }

        return $product->fresh(['variants', 'categories']);
    }

    private function resolveWarehouse(): Warehouse
    {
        $defaultId = config('ez-ecommerce.inventory.default_warehouse_id');
        if ($defaultId !== null) {
            return Warehouse::query()->findOrFail($defaultId);
        }

        $existing = Warehouse::query()->first();
        if ($existing !== null) {
            return $existing;
        }

        return Warehouse::query()->create([
            'name' => 'Default',
            'code' => 'DEFAULT',
            'is_active' => true,
        ]);
    }
}
