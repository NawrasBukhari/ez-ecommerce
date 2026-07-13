<?php

namespace EzEcommerce\Core\Enums;

enum PaymentStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Authorized = 'authorized';
    case PartiallyCaptured = 'partially_captured';
    case Captured = 'captured';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Reversed = 'reversed';
}
