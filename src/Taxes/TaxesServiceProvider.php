<?php

namespace EzEcommerce\Taxes;

use EzEcommerce\Taxes\Contracts\TaxCalculator;
use Illuminate\Support\ServiceProvider;

class TaxesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TaxCalculator::class, function ($app): TaxCalculator {
            return match (config('ez-ecommerce.drivers.tax.default', 'simple')) {
                'jurisdiction' => $app->make(JurisdictionTaxCalculator::class),
                default => $app->make(SimpleTaxCalculator::class),
            };
        });
    }
}
