<?php

namespace EzEcommerce\Webhooks\Outbound\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $url
 * @property string|null $secret
 * @property ArrayObject<int|string, mixed>|null $events
 * @property ArrayObject<int|string, mixed>|null $metadata
 */
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
