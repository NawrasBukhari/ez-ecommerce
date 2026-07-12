<?php

namespace EzEcommerce\Subscriptions\Models;

use EzEcommerce\Core\Enums\SubscriptionInterval;
use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_subscription_plans';

    protected $fillable = [
        'name',
        'interval',
        'interval_count',
        'amount_minor',
        'currency',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'interval' => SubscriptionInterval::class,
            'metadata' => AsArrayObject::class,
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
