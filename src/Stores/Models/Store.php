<?php

namespace EzEcommerce\Stores\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;

class Store extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_stores';

    protected $fillable = [
        'name',
        'slug',
        'currency',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => AsArrayObject::class,
        ];
    }
}
