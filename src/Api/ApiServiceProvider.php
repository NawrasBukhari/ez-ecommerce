<?php

namespace EzEcommerce\Api;

use EzEcommerce\Api\Http\Middleware\CommerceApiToken;
use EzEcommerce\Api\Http\Middleware\GuestCartToken;
use EzEcommerce\Api\Http\Middleware\ValidateCheckoutCartAccess;
use EzEcommerce\Api\Http\RegistersCommerceApiExceptions;
use EzEcommerce\B2B\Models\Company;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartItem;
use EzEcommerce\Catalog\Models\Category;
use EzEcommerce\Catalog\Models\Product;
use EzEcommerce\Customers\Models\Address;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Customers\Models\CustomerGroup;
use EzEcommerce\Inventory\Models\InventoryReservation;
use EzEcommerce\Inventory\Models\Warehouse;
use EzEcommerce\Marketplace\Models\Vendor;
use EzEcommerce\Marketplace\Models\VendorPayout;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Returns\Models\ReturnItem;
use EzEcommerce\Returns\Models\ReturnRequest;
use EzEcommerce\Stores\Models\Store;
use EzEcommerce\Subscriptions\Models\Subscription;
use EzEcommerce\Subscriptions\Models\SubscriptionPlan;
use EzEcommerce\Webhooks\Outbound\Models\WebhookDelivery;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        if (! config('ez-ecommerce.features.api', false)) {
            return;
        }

        $this->registerRouteBindings();
        $this->loadRoutesFrom(dirname(__DIR__, 2).'/routes/api.php');

        JsonResource::withoutWrapping();

        $router = $this->app['router'];
        $router->aliasMiddleware('guest.cart', GuestCartToken::class);
        $router->aliasMiddleware('commerce.api', CommerceApiToken::class);
        $router->aliasMiddleware('checkout.cart', ValidateCheckoutCartAccess::class);

        RegistersCommerceApiExceptions::register();
    }

    private function registerRouteBindings(): void
    {
        Route::bind('product', function (string $value): Product {
            return Product::query()
                ->where('public_id', $value)
                ->orWhere('slug', $value)
                ->firstOrFail();
        });

        Route::bind('cart', function (string $value): Cart {
            return Cart::query()->where('public_id', $value)->firstOrFail();
        });

        Route::bind('order', function (string $value): Order {
            return Order::query()->where('public_id', $value)->firstOrFail();
        });

        Route::bind('store', function (string $value): Store {
            return Store::query()
                ->where('public_id', $value)
                ->orWhere('slug', $value)
                ->firstOrFail();
        });

        Route::bind('company', function (string $value): Company {
            return Company::query()->where('public_id', $value)->firstOrFail();
        });

        Route::bind('vendor', function (string $value): Vendor {
            return Vendor::query()
                ->where('public_id', $value)
                ->orWhere('slug', $value)
                ->firstOrFail();
        });

        Route::bind('subscription', function (string $value): Subscription {
            return Subscription::query()->where('public_id', $value)->firstOrFail();
        });

        Route::bind('customer', function (string $value): Customer {
            return Customer::query()->where('public_id', $value)->firstOrFail();
        });

        Route::bind('address', function (string $value, $route): Address {
            /** @var Customer $customer */
            $customer = $route->parameter('customer');

            return Address::query()
                ->where('customer_id', $customer->id)
                ->where('public_id', $value)
                ->firstOrFail();
        });

        Route::bind('return', function (string $value): ReturnRequest {
            return ReturnRequest::query()->where('public_id', $value)->firstOrFail();
        });

        Route::bind('returnItem', function (string $value, $route): ReturnItem {
            /** @var ReturnRequest $return */
            $return = $route->parameter('return');

            return ReturnItem::query()
                ->where('return_id', $return->id)
                ->where('id', $value)
                ->firstOrFail();
        });

        Route::bind('warehouse', function (string $value): Warehouse {
            return Warehouse::query()->where('public_id', $value)->firstOrFail();
        });

        Route::bind('item', function (string $value, $route): CartItem {
            /** @var Cart $cart */
            $cart = $route->parameter('cart');

            return CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('id', $value)
                ->firstOrFail();
        });

        Route::bind('category', function (string $value): Category {
            return Category::query()
                ->where('public_id', $value)
                ->orWhere('slug', $value)
                ->firstOrFail();
        });

        Route::bind('customerGroup', function (string $value): CustomerGroup {
            return CustomerGroup::query()->where('public_id', $value)->firstOrFail();
        });

        Route::bind('plan', function (string $value): SubscriptionPlan {
            return SubscriptionPlan::query()->where('public_id', $value)->firstOrFail();
        });

        Route::bind('payout', function (string $value): VendorPayout {
            return VendorPayout::query()->where('public_id', $value)->firstOrFail();
        });

        Route::bind('payment', function (string $value, $route): Payment {
            /** @var Order $order */
            $order = $route->parameter('order');

            return Payment::query()
                ->where('order_id', $order->id)
                ->where('public_id', $value)
                ->firstOrFail();
        });

        Route::bind('reservation', function (string $value): InventoryReservation {
            return InventoryReservation::query()->findOrFail($value);
        });

        Route::bind('webhookDelivery', function (string $value): WebhookDelivery {
            return WebhookDelivery::query()->findOrFail($value);
        });
    }
}
