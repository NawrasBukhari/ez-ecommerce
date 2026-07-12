<?php

namespace EzEcommerce\Returns;

use EzEcommerce\Returns\Actions\MarkReturnedItemAsDamaged;
use EzEcommerce\Returns\Actions\ReceiveReturn;
use EzEcommerce\Returns\Actions\RestockReturnedItem;
use Illuminate\Support\ServiceProvider;

class ReturnsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReceiveReturn::class);
        $this->app->singleton(RestockReturnedItem::class);
        $this->app->singleton(MarkReturnedItemAsDamaged::class);
    }
}
