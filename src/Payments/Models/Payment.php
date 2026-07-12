<?php

namespace EzEcommerce\Payments\Models;

use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Refunds\Models\Refund;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_payments';

    protected $fillable = [
        'order_id',
        'gateway',
        'amount_minor',
        'currency',
        'status',
        'authorized_minor',
        'captured_minor',
        'refunded_minor',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'metadata' => AsArrayObject::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
