<?php

namespace EzEcommerce\Core\Enums;

enum AdjustmentOrigin: string
{
    case System = 'system';
    case Promotion = 'promotion';
    case Manual = 'manual';
    case Imported = 'imported';
}
