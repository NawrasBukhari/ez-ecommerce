<?php

namespace EzEcommerce\Stores;

use EzEcommerce\Stores\Contracts\StoreContext;
use Illuminate\Support\ServiceProvider;

class StoresServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StoreContext::class, DefaultStoreContext::class);
    }
}
