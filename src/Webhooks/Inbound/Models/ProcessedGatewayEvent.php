<?php

namespace EzEcommerce\Webhooks\Inbound\Models;

use EzEcommerce\Core\Models\CommerceModel;

class ProcessedGatewayEvent extends CommerceModel
{
    protected $table = 'commerce_processed_gateway_events';

    protected $fillable = [
        'gateway',
        'external_event_id',
        'event_type',
        'payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
