<?php

namespace EzEcommerce\Webhooks\Inbound\Models;

use EzEcommerce\Core\Models\CommerceModel;

/**
 * @property string $status
 */
class ProcessedGatewayEvent extends CommerceModel
{
    protected $table = 'commerce_processed_gateway_events';

    protected $fillable = [
        'gateway',
        'external_event_id',
        'event_type',
        'status',
        'payload',
        'processed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
