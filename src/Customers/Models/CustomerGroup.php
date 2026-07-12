<?php

namespace EzEcommerce\Customers\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerGroup extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_customer_groups';

    protected $fillable = [
        'name',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => AsArrayObject::class,
        ];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
