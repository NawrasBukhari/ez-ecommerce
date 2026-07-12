<?php

namespace EzEcommerce\Cart\Exceptions;

use EzEcommerce\Cart\Models\Cart;
use RuntimeException;

final class CartVersionConflictException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $cartVersion,
    ) {
        parent::__construct($message);
    }

    public static function for(Cart $cart): self
    {
        return new self(
            "Cart [{$cart->public_id}] was modified concurrently (version {$cart->version}).",
            $cart->version,
        );
    }
}
