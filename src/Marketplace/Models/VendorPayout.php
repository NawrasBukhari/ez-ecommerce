<?php

namespace EzEcommerce\Marketplace\Models;

use EzEcommerce\Core\Models\CommerceModel;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorPayout extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_vendor_payouts';

    protected $fillable = [
        'vendor_id',
        'amount_minor',
        'currency',
        'commission_count',
        'paid_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'immutable_datetime',
            'metadata' => AsArrayObject::class,
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(VendorCommission::class, 'payout_id');
    }
}
