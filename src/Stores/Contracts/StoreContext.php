<?php

namespace EzEcommerce\Stores\Contracts;

use EzEcommerce\Stores\Models\Store;

interface StoreContext
{
    public function current(): ?Store;

    public function id(): ?int;
}
