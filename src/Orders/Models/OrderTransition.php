<?php

namespace EzEcommerce\Orders\Models;

use EzEcommerce\Core\Enums\TransitionDimension;
use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OrderTransition extends CommerceModel
{
    public const UPDATED_AT = null;

    protected $table = 'commerce_order_transitions';

    protected $fillable = [
        'order_id',
        'dimension',
        'from_state',
        'to_state',
        'actor_type',
        'actor_id',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'dimension' => TransitionDimension::class,
            'metadata' => AsArrayObject::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
