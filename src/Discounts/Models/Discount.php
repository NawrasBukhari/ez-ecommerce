<?php

namespace EzEcommerce\Discounts\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;

class Discount extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_discounts';

    protected $fillable = [
        'code',
        'type',
        'value',
        'is_active',
        'valid_from',
        'valid_to',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'metadata' => AsArrayObject::class,
        ];
    }
}
