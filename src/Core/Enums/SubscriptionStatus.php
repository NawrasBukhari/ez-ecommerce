<?php

namespace EzEcommerce\Core\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Trialing = 'trialing';
}
