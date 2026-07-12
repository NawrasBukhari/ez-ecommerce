<?php

namespace EzEcommerce\Core\Enums;

enum SubscriptionInterval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
