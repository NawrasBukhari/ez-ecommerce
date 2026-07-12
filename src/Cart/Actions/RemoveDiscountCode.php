<?php

namespace EzEcommerce\Cart\Actions;

use EzEcommerce\Cart\Exceptions\CartVersionConflictException;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartAdjustment;
use EzEcommerce\Core\Enums\AdjustmentOrigin;
use EzEcommerce\Core\Enums\AdjustmentType;
use Illuminate\Support\Facades\DB;

final class RemoveDiscountCode
{
    public function execute(Cart $cart, ?string $code = null, ?int $expectedVersion = null): Cart
    {
        return DB::transaction(function () use ($cart, $code, $expectedVersion): Cart {
            $locked = Cart::query()->lockForUpdate()->findOrFail($cart->id);

            if ($expectedVersion !== null && $locked->version !== $expectedVersion) {
                throw CartVersionConflictException::for($locked);
            }

            $query = CartAdjustment::query()
                ->where('cart_id', $locked->id)
                ->where('origin', AdjustmentOrigin::Promotion)
                ->where('type', AdjustmentType::Discount);

            if ($code !== null) {
                $query->where('code', $code);
            }

            $deleted = $query->delete();

            if ($deleted > 0) {
                $updated = Cart::query()
                    ->where('id', $locked->id)
                    ->where('version', $locked->version)
                    ->update(['version' => $locked->version + 1]);

                if ($expectedVersion !== null && $updated === 0) {
                    throw CartVersionConflictException::for($locked);
                }
            }

            return $locked->fresh(['items', 'adjustments']);
        });
    }
}
