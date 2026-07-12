<?php

namespace EzEcommerce\Customers\Models;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Orders\Models\Order;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Customer extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_customers';

    protected $fillable = [
        'actor_type',
        'actor_id',
        'email',
        'first_name',
        'last_name',
        'phone',
        'customer_group_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => AsArrayObject::class,
        ];
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
