<?php

namespace EzEcommerce\Inventory\Models;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Core\Enums\ReservationStatus;
use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Orders\Models\Order;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ReservationStatus $status
 * @property int $quantity
 * @property int|null $order_id
 * @property int $balance_id
 * @property \DateTimeImmutable|null $expires_at
 */
class InventoryReservation extends CommerceModel
{
    protected $table = 'commerce_inventory_reservations';

    protected $fillable = [
        'cart_id',
        'order_id',
        'balance_id',
        'quantity',
        'status',
        'expires_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'expires_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function balance(): BelongsTo
    {
        return $this->belongsTo(InventoryBalance::class, 'balance_id');
    }
}
