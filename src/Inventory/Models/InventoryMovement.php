<?php

namespace EzEcommerce\Inventory\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryMovement extends CommerceModel
{
    protected $table = 'commerce_inventory_movements';

    protected $fillable = [
        'balance_id',
        'type',
        'on_hand_delta',
        'reserved_delta',
        'reference_type',
        'reference_id',
        'idempotency_scope',
        'idempotency_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => AsArrayObject::class,
        ];
    }

    public function balance(): BelongsTo
    {
        return $this->belongsTo(InventoryBalance::class, 'balance_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
