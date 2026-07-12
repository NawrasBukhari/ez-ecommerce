<?php

namespace EzEcommerce\Core\Enums;

enum ReturnStatus: string
{
    case Requested = 'requested';
    case Received = 'received';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
