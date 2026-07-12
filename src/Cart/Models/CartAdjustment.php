<?php

namespace EzEcommerce\Cart\Models;

use EzEcommerce\Core\Enums\AdjustmentOrigin;
use EzEcommerce\Core\Enums\AdjustmentType;
use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CartAdjustment extends CommerceModel
{
    protected $table = 'commerce_cart_adjustments';

    protected $fillable = [
        'cart_id',
        'cart_item_id',
        'type',
        'source_type',
        'source_id',
        'code',
        'label',
        'amount_minor',
        'currency',
        'origin',
        'included_in_unit_price',
        'affects_total',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => AdjustmentType::class,
            'origin' => AdjustmentOrigin::class,
            'included_in_unit_price' => 'boolean',
            'affects_total' => 'boolean',
            'metadata' => AsArrayObject::class,
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function cartItem(): BelongsTo
    {
        return $this->belongsTo(CartItem::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
