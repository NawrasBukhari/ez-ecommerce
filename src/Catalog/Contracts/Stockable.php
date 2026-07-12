<?php

namespace EzEcommerce\Catalog\Contracts;

interface Stockable
{
    public function stockIdentifier(): string;
}
