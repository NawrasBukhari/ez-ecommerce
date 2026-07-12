<?php

namespace EzEcommerce\Returns\Models;

use EzEcommerce\Core\Enums\ReturnStatus;
use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Orders\Models\Order;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnRequest extends CommerceModel
{
    public const MORPH_ALIAS = 'commerce_return';

    protected static bool $usesPublicId = true;

    protected $table = 'commerce_returns';

    protected $fillable = [
        'order_id',
        'customer_id',
        'status',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReturnStatus::class,
            'metadata' => AsArrayObject::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }
}
