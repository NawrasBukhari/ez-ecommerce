<?php

namespace EzEcommerce\Refunds\Models;

use EzEcommerce\Core\Enums\RefundStatus;
use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_refunds';

    protected $fillable = [
        'payment_id',
        'order_id',
        'amount_minor',
        'currency',
        'status',
        'reason',
        'external_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'metadata' => AsArrayObject::class,
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
