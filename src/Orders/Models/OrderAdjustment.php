<?php

namespace EzEcommerce\Orders\Models;

use EzEcommerce\Core\Enums\AdjustmentOrigin;
use EzEcommerce\Core\Enums\AdjustmentType;
use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OrderAdjustment extends CommerceModel
{
    protected $table = 'commerce_order_adjustments';

    protected $fillable = [
        'order_id',
        'order_item_id',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
