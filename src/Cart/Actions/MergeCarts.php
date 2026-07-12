<?php

namespace EzEcommerce\Cart\Actions;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartItem;
use Illuminate\Support\Facades\DB;

final class MergeCarts
{
    public function __construct(
        private readonly CalculateCartTotals $calculateCartTotals,
    ) {
    }

    public function execute(Cart $guestCart, Cart $customerCart): Cart
    {
        return DB::transaction(function () use ($guestCart, $customerCart) {
            $guestCart = Cart::query()->lockForUpdate()->findOrFail($guestCart->id);
            $customerCart = Cart::query()->lockForUpdate()->findOrFail($customerCart->id);

            foreach ($guestCart->items as $guestItem) {
                $existing = CartItem::query()
                    ->where('cart_id', $customerCart->id)
                    ->where('purchasable_type', $guestItem->purchasable_type)
                    ->where('purchasable_id', $guestItem->purchasable_id)
                    ->first();

                if ($existing !== null) {
                    $existing->update(['quantity' => $existing->quantity + $guestItem->quantity]);
                    $guestItem->delete();
                } else {
                    $guestItem->update(['cart_id' => $customerCart->id]);
                }
            }

            $customerCart->update(['version' => $customerCart->version + 1]);
            $guestCart->delete();

            return $this->calculateCartTotals->execute($customerCart);
        });
    }
}
