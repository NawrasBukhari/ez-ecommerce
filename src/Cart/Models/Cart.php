<?php

namespace EzEcommerce\Cart\Models;

use ArrayObject;
use EzEcommerce\Core\Enums\CartStatus;
use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Inventory\Models\InventoryReservation;
use EzEcommerce\Orders\Models\Order;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $version
 * @property int|null $customer_id
 * @property string $currency
 * @property CartStatus $status
 * @property int $subtotal_minor
 * @property int $discount_total_minor
 * @property int $tax_total_minor
 * @property int $shipping_total_minor
 * @property int $fee_total_minor
 * @property int $grand_total_minor
 * @property ArrayObject<int|string, mixed>|null $metadata
 * @property-read Customer|null $customer
 */
class Cart extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_carts';

    protected $fillable = [
        'customer_id',
        'store_id',
        'guest_token_hash',
        'status',
        'currency',
        'version',
        'expires_at',
        'subtotal_minor',
        'discount_total_minor',
        'tax_total_minor',
        'shipping_total_minor',
        'fee_total_minor',
        'grand_total_minor',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => CartStatus::class,
            'expires_at' => 'immutable_datetime',
            'metadata' => AsArrayObject::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(CartAdjustment::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }
}
