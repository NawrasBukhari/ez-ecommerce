<?php

namespace EzEcommerce\Catalog\Contracts;

interface Purchasable
{
    public function purchasableId(): string;

    public function purchasableType(): string;

    public function purchasableName(): string;

    /** @return array<string, mixed> */
    public function purchasableMetadata(): array;
}
