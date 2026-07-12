<?php

namespace EzEcommerce\Subscriptions\Models;

use EzEcommerce\Core\Enums\SubscriptionStatus;
use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $public_id
 * @property \Illuminate\Database\Eloquent\Casts\ArrayObject<int|string, mixed>|null $metadata
 */
class Subscription extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_subscriptions';

    protected $fillable = [
        'customer_id',
        'plan_id',
        'status',
        'current_period_start',
        'current_period_end',
        'payment_method',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'metadata' => AsArrayObject::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }
}
