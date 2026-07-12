<?php

namespace EzEcommerce\Payments\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentAttempt extends CommerceModel
{
    protected $table = 'commerce_payment_attempts';

    protected $fillable = [
        'payment_id',
        'operation',
        'idempotency_key',
        'status',
        'external_id',
        'redirect_url',
        'client_secret',
        'error_code',
        'error_message',
        'request_metadata',
        'response_metadata',
    ];

    protected function casts(): array
    {
        return [
            'request_metadata' => AsArrayObject::class,
            'response_metadata' => AsArrayObject::class,
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'attempt_id');
    }
}
