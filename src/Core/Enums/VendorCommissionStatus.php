<?php

namespace EzEcommerce\Core\Enums;

enum VendorCommissionStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
