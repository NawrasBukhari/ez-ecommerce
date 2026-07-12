<?php

namespace EzEcommerce\Core\Enums;

enum AdjustmentType: string
{
    case Discount = 'discount';
    case Tax = 'tax';
    case Shipping = 'shipping';
    case Fee = 'fee';
    case Credit = 'credit';
    case Rounding = 'rounding';
}
