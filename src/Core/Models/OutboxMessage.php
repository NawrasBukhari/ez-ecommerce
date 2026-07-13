<?php

namespace EzEcommerce\Core\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;

/**
 * @property string $event
 * @property string $key
 * @property string $status
 * @property \ArrayObject $payload
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
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => AsArrayObject::class,
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function scopePending($query)
    {
        $query->where('status', 'pending')->orderBy('id');
    }
}
