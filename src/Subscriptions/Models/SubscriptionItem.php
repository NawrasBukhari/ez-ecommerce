<?php

namespace EzEcommerce\Subscriptions\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SubscriptionItem extends CommerceModel
{
    protected $table = 'commerce_subscription_items';

    protected $fillable = [
        'subscription_id',
        'purchasable_type',
        'purchasable_id',
        'quantity',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }
}
