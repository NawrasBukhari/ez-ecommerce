<?php

namespace EzEcommerce\Customers;

use EzEcommerce\Customers\Contracts\CustomerResolver;
use Illuminate\Support\ServiceProvider;

class CustomersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CustomerResolver::class, DefaultCustomerResolver::class);
    }
}
