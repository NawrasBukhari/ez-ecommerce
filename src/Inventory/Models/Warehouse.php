<?php

namespace EzEcommerce\Inventory\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_warehouses';

    protected $fillable = [
        'name',
        'code',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => AsArrayObject::class,
        ];
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }
}
