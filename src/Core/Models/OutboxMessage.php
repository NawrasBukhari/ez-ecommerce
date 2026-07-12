<?php

namespace EzEcommerce\Core\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;

class OutboxMessage extends CommerceModel
{
    protected $table = 'commerce_outbox_messages';

    protected $fillable = [
        'event',
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
}
