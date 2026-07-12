<?php

namespace EzEcommerce\Customers\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $customer_id
 * @property string|null $type
 * @property string|null $line1
 * @property string|null $line2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postal_code
 * @property string|null $country_code
 */
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
        'country_code',
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
