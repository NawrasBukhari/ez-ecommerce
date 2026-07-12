<?php

namespace EzEcommerce\Core\Enums;

enum OrderPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case RequiresAction = 'requires_action';
    case Authorized = 'authorized';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Failed = 'failed';
}
