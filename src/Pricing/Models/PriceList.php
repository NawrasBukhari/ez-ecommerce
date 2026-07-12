<?php

namespace EzEcommerce\Pricing\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_price_lists';

    protected $fillable = [
        'name',
        'currency',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => AsArrayObject::class,
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }
}
