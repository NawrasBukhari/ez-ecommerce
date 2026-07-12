<?php

namespace EzEcommerce\Catalog;

use EzEcommerce\Catalog\Models\Product;
use EzEcommerce\Catalog\Models\ProductVariant;

final class CatalogManager
{
    public function findProductBySlug(string $slug): ?Product
    {
        return Product::query()->where('slug', $slug)->first();
    }

    public function findVariantByPublicId(string $publicId): ?ProductVariant
    {
        return ProductVariant::query()->where('public_id', $publicId)->first();
    }

    public function findVariantBySku(string $sku): ?ProductVariant
    {
        return ProductVariant::query()->where('sku', $sku)->first();
    }
}
