<?php

namespace EzEcommerce\Cart\Actions\Concerns;

use EzEcommerce\Cart\Exceptions\CartVersionConflictException;
use EzEcommerce\Cart\Models\Cart;
use Illuminate\Support\Facades\DB;

trait BumpsCartVersionAtomically
{
    protected function withCartVersionBump(Cart $cart, ?int $expectedVersion, callable $mutate): mixed
    {
        return DB::transaction(function () use ($cart, $expectedVersion, $mutate) {
            $locked = Cart::query()->lockForUpdate()->findOrFail($cart->id);

            if ($expectedVersion !== null && $locked->version !== $expectedVersion) {
                throw CartVersionConflictException::for($locked);
            }

            $result = $mutate($locked);

            $updated = Cart::query()
                ->where('id', $locked->id)
                ->where('version', $locked->version)
                ->update(['version' => $locked->version + 1]);

            if ($expectedVersion !== null && $updated === 0) {
                throw CartVersionConflictException::for($locked);
            }

            return $result;
        });
    }
}
