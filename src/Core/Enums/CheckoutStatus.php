<?php

namespace EzEcommerce\Core\Enums;

enum CheckoutStatus: string
{
    case Completed = 'completed';
    case PendingPayment = 'pending_payment';
    case RequiresAction = 'requires_action';
    case PaymentSessionFailed = 'payment_session_failed';
    case FinalizationFailed = 'finalization_failed';
}
