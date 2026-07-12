<?php

namespace EzEcommerce\Taxes\Contracts;

use EzEcommerce\Taxes\Data\TaxRequest;
use EzEcommerce\Taxes\Data\TaxResult;

interface TaxCalculator
{
    public function calculate(TaxRequest $request): TaxResult;
}
