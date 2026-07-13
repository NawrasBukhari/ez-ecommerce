<?php

namespace EzEcommerce\Core\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;

/**
 * @property string $event
 * @property string|null $key
 * @property string $status
 * @property \ArrayObject $payload
 * @property \Carbon\CarbonImmutable|null $available_at
 * @property \Carbon\CarbonImmutable|null $locked_at
 * @property \Carbon\CarbonImmutable|null $locked_until
 * @property string|null $lock_token
 * @property int $attempts
 * @property string|null $last_error
 * @property \Carbon\CarbonImmutable|null $processed_at
 */
class OutboxMessage extends CommerceModel
{
    protected $table = 'commerce_outbox_messages';

    protected $fillable = [
        'event',
        'key',
        'status',
        'payload',
        'available_at',
        'locked_at',
        'locked_until',
        'lock_token',
        'attempts',
        'last_error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => AsArrayObject::class,
            'processed_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    public function scopePending($query): void
    {
        $query->where('status', 'pending')->orderBy('id');
    }

    /**
     * Rows a worker may claim: pending, retryable rows whose backoff has
     * elapsed, or stale processing rows whose lease has expired.
     */
    public function scopeClaimable($query): void
    {
        $query->where(function ($query) {
            $query->where('status', 'pending')
                ->orWhere(function ($query) {
                    $query->where('status', 'failed_retryable')
                        ->where('available_at', '<=', now());
                })
                ->orWhere(function ($query) {
                    $query->where('status', 'processing')
                        ->where('locked_until', '<', now());
                });
        })->orderBy('id');
    }
}
