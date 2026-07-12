<?php

namespace EzEcommerce\Orders\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAddress extends CommerceModel
{
    protected static bool $usesPublicId = false;

    protected $table = 'commerce_order_addresses';

    protected $fillable = [
        'order_id',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
