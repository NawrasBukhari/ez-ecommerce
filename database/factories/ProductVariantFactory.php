<?php

namespace EzEcommerce\Database\Factories;

use EzEcommerce\Catalog\Models\Product;
use EzEcommerce\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariant> */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####-????')),
            'name' => fake()->word(),
            'metadata' => ['weight_grams' => fake()->numberBetween(100, 5000)],
        ];
    }
}
