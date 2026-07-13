<?php

namespace EzEcommerce\Fulfillment\Models;

use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fulfillment extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_fulfillments';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'quantity',
        'idempotency_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
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
}
