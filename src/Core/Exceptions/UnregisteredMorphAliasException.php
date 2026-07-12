<?php

namespace EzEcommerce\Core\Exceptions;

use RuntimeException;

final class UnregisteredMorphAliasException extends RuntimeException
{
    public static function for(string $alias): self
    {
        return new self("Unregistered morph alias: {$alias}");
    }
}
