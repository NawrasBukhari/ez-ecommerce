<?php

namespace EzEcommerce\Webhooks\Inbound\Models;

use EzEcommerce\Core\Models\CommerceModel;

class ProcessedGatewayEvent extends CommerceModel
{
    protected $table = 'commerce_processed_gateway_events';

    protected $fillable = [
        'gateway',
        'external_id',
        'event_type',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'immutable_datetime',
        ];
    }
}
