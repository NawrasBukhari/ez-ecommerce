<?php

namespace EzEcommerce\Core;

use EzEcommerce\Catalog\Models\Product;
use EzEcommerce\Catalog\Models\ProductVariant;
use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Idempotency\IdempotencyStore;
use EzEcommerce\Core\Support\MorphMap;
use EzEcommerce\Core\Support\SystemClock;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->singleton(IdempotencyStore::class);

        MorphMap::register([
            Product::MORPH_ALIAS => Product::class,
            ProductVariant::MORPH_ALIAS => ProductVariant::class,
        ]);
    }
}
