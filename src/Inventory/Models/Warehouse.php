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
        'is_default',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'metadata' => AsArrayObject::class,
        ];
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }
}
