<?php

namespace EzEcommerce\Marketplace\Models;

use EzEcommerce\Catalog\Models\Product;
use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_vendors';

    protected $fillable = [
        'name',
        'slug',
        'commission_rate',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:4',
            'metadata' => AsArrayObject::class,
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(VendorCommission::class);
    }
}
