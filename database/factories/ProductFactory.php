<?php

namespace EzEcommerce\Database\Factories;

use EzEcommerce\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'name' => $name,
            'slug' => str()->slug($name).'-'.fake()->unique()->numerify('###'),
            'description' => fake()->paragraph(),
            'type' => 'physical',
            'metadata' => [],
        ];
    }
}
