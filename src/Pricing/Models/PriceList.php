<?php

namespace EzEcommerce\Pricing\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $currency
 * @property string|null $code
 */
class PriceList extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_price_lists';

    protected $fillable = [
        'name',
        'currency',
        'code',
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
