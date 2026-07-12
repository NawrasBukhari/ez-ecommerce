<?php

namespace EzEcommerce\B2B\Models;

use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_companies';

    protected $fillable = [
        'name',
        'tax_id',
        'payment_terms_days',
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
