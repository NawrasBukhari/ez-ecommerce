<?php

namespace EzEcommerce\Core\Enums;

enum ReservationStatus: string
{
    case Active = 'active';
    case Committed = 'committed';
    case Released = 'released';
    case Expired = 'expired';
}
