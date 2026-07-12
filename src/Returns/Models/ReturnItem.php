<?php

namespace EzEcommerce\Returns\Models;

use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnItem extends CommerceModel
{
    protected $table = 'commerce_return_items';

    protected $fillable = [
        'return_id',
        'order_item_id',
        'quantity',
        'restock',
        'damaged',
    ];

    protected function casts(): array
    {
        return [
            'restock' => 'boolean',
            'damaged' => 'boolean',
        ];
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
