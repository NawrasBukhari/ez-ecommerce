<?php

namespace EzEcommerce\Customers\Contracts;

use EzEcommerce\Customers\Data\CustomerIdentity;
use EzEcommerce\Customers\Data\CustomerResolutionContext;
use EzEcommerce\Customers\Models\Customer;

interface CustomerResolver
{
    public function resolve(CustomerIdentity $identity, CustomerResolutionContext $context): ?Customer;
}
