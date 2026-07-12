<?php

namespace EzEcommerce\Payments\Models;

use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $external_id
 */
class PaymentTransaction extends CommerceModel
{
    protected $table = 'commerce_payment_transactions';

    protected $fillable = [
        'payment_id',
        'attempt_id',
        'type',
        'amount_minor',
        'currency',
        'external_id',
        'status',
        'processed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentTransactionType::class,
            'processed_at' => 'immutable_datetime',
            'metadata' => AsArrayObject::class,
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'attempt_id');
    }
}
