<?php

namespace EzEcommerce\Core\Enums;

enum TransitionDimension: string
{
    case Commercial = 'commercial';
    case Payment = 'payment';
    case Fulfillment = 'fulfillment';
}
