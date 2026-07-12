<?php

namespace EzEcommerce\Discounts;

use EzEcommerce\Core\Support\MorphMap;
use EzEcommerce\Discounts\Models\Discount;
use Illuminate\Support\ServiceProvider;

class DiscountsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        MorphMap::register([
            'discount' => Discount::class,
        ]);
    }
}
