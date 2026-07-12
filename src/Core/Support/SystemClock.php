<?php

namespace EzEcommerce\Core\Support;

use DateTimeImmutable;
use EzEcommerce\Core\Contracts\Clock;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }
}
