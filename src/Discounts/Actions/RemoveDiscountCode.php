<?php

namespace EzEcommerce\Discounts\Actions;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartAdjustment;
use EzEcommerce\Core\Enums\AdjustmentOrigin;
use EzEcommerce\Core\Enums\AdjustmentType;

final class RemoveDiscountCode
{
    public function execute(Cart $cart, ?string $code = null): Cart
    {
        $query = CartAdjustment::query()
            ->where('cart_id', $cart->id)
            ->where('origin', AdjustmentOrigin::Promotion)
            ->where('type', AdjustmentType::Discount);

        if ($code !== null) {
            $query->where('code', $code);
        }

        $query->delete();

        return $cart->fresh(['adjustments']);
    }
}
