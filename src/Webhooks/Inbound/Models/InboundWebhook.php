<?php

namespace EzEcommerce\Webhooks\Inbound\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;

class InboundWebhook extends CommerceModel
{
    protected $table = 'commerce_inbound_webhooks';

    protected $fillable = [
        'gateway',
        'event_type',
        'external_id',
        'payload',
        'status',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => AsArrayObject::class,
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
