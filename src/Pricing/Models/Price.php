<?php

namespace EzEcommerce\Pricing\Models;

use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Customers\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Price extends CommerceModel
{
    protected $table = 'commerce_prices';

    protected $fillable = [
        'priceable_type',
        'priceable_id',
        'amount_minor',
        'currency',
        'type',
        'customer_id',
        'customer_group_id',
        'price_list_id',
        'valid_from',
        'valid_to',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'metadata' => AsArrayObject::class,
        ];
    }

    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
