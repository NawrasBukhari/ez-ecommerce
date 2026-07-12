<?php

namespace EzEcommerce\Inventory\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryBalance extends CommerceModel
{
    protected $table = 'commerce_inventory_balances';

    protected $fillable = [
        'warehouse_id',
        'stockable_type',
        'stockable_id',
        'on_hand',
        'reserved',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'balance_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class, 'balance_id');
    }
}
