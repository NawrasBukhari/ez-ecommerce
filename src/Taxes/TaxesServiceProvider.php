<?php

namespace EzEcommerce\Taxes;

use EzEcommerce\Taxes\Contracts\TaxCalculator;
use Illuminate\Support\ServiceProvider;

class TaxesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TaxCalculator::class, SimpleTaxCalculator::class);
    }
}
