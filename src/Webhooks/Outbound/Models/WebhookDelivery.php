<?php

namespace EzEcommerce\Webhooks\Outbound\Models;

use EzEcommerce\Core\Enums\WebhookDeliveryStatus;
use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends CommerceModel
{
    protected $table = 'commerce_webhook_deliveries';

    protected $fillable = [
        'endpoint_id',
        'event',
        'payload',
        'status',
        'attempts',
        'response_code',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => AsArrayObject::class,
            'status' => WebhookDeliveryStatus::class,
            'delivered_at' => 'immutable_datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }
}
