<?php

namespace EzEcommerce\Core\Enums;

enum PaymentTransactionType: string
{
    case Authorization = 'authorization';
    case Capture = 'capture';
    case Void = 'void';
    case Refund = 'refund';
    case Chargeback = 'chargeback';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';
}
