<?php

namespace EzEcommerce\Catalog\Contracts;

interface Shippable
{
    public function requiresShipping(): bool;

    public function weightGrams(): ?int;
}
