<?php

namespace EzEcommerce\Catalog\Contracts;

interface Taxable
{
    public function taxCategory(): ?string;
}
