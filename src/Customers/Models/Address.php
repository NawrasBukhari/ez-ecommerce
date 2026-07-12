<?php

namespace EzEcommerce\Customers\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_addresses';

    protected $fillable = [
        'customer_id',
        'type',
        'line1',
        'line2',
        'city',
        'state',
        'postal_code',
        'country',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => AsArrayObject::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
