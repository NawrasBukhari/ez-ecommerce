<?php

namespace EzEcommerce\Orders\Models;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Core\Enums\FulfillmentStatus;
use EzEcommerce\Core\Enums\OrderPaymentStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Models\CommerceModel;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Fulfillment\Models\Fulfillment;
use EzEcommerce\Inventory\Models\InventoryReservation;
use EzEcommerce\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends CommerceModel
{
    protected static bool $usesPublicId = true;

    protected $table = 'commerce_orders';

    protected $fillable = [
        'store_id',
        'customer_id',
        'cart_id',
        'status',
        'payment_status',
        'fulfillment_status',
        'currency',
        'subtotal_minor',
        'discount_total_minor',
        'tax_total_minor',
        'shipping_total_minor',
        'fee_total_minor',
        'grand_total_minor',
        'refunded_total_minor',
        'shipping_method',
        'payment_method',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => OrderPaymentStatus::class,
            'fulfillment_status' => FulfillmentStatus::class,
            'metadata' => AsArrayObject::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(OrderAdjustment::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(OrderTransition::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(Fulfillment::class);
    }
}
