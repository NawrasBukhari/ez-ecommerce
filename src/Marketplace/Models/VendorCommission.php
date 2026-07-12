<?php

namespace EzEcommerce\Marketplace\Models;

use EzEcommerce\Core\Enums\VendorCommissionStatus;
use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorCommission extends CommerceModel
{
    protected $table = 'commerce_vendor_commissions';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'vendor_id',
        'amount_minor',
        'currency',
        'status',
        'payout_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => VendorCommissionStatus::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(VendorPayout::class, 'payout_id');
    }
}
