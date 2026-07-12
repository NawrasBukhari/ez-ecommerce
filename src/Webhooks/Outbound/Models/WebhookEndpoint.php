<?php

namespace EzEcommerce\Webhooks\Outbound\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends CommerceModel
{
    protected $table = 'commerce_webhook_endpoints';

    protected $fillable = [
        'url',
        'secret',
        'events',
        'active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'events' => AsArrayObject::class,
            'active' => 'boolean',
            'metadata' => AsArrayObject::class,
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }
}
