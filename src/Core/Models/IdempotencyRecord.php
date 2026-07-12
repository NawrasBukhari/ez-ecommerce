<?php

namespace EzEcommerce\Core\Models;

use EzEcommerce\Core\Enums\IdempotencyStatus;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;

class IdempotencyRecord extends CommerceModel
{
    protected $table = 'commerce_idempotency_records';

    protected $fillable = [
        'scope',
        'key',
        'request_hash',
        'status',
        'resource_type',
        'resource_id',
        'response_code',
        'response_payload',
        'attempts',
        'last_error',
        'locked_at',
        'locked_until',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => IdempotencyStatus::class,
            'response_payload' => AsArrayObject::class,
            'locked_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
