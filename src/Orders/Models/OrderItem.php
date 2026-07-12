<?php

namespace EzEcommerce\Orders\Models;

use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Fulfillment\Models\Fulfillment;
use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ArrayObject<int|string, mixed>|null $product_snapshot
 */
class OrderItem extends CommerceModel
{
    protected $table = 'commerce_order_items';

    protected $fillable = [
        'order_id',
        'name',
        'sku',
        'quantity',
        'unit_price_minor',
        'subtotal_minor',
        'discount_minor',
        'tax_minor',
        'total_minor',
        'price_source',
        'price_record_id',
        'price_quote_hash',
        'price_metadata',
        'priced_at',
        'product_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'price_metadata' => AsArrayObject::class,
            'product_snapshot' => AsArrayObject::class,
            'priced_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(OrderAdjustment::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(Fulfillment::class);
    }
}
