<?php

namespace EzEcommerce\Api;

use EzEcommerce\Api\Http\Middleware\CommerceApiToken;
use EzEcommerce\Api\Http\Middleware\GuestCartToken;
use EzEcommerce\B2B\Models\Company;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartItem;
use EzEcommerce\Catalog\Models\Product;
use EzEcommerce\Marketplace\Models\Vendor;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Stores\Models\Store;
use EzEcommerce\Subscriptions\Models\Subscription;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ApiServiceProvider extends ServiceProvider
{
    public function register(): void {}

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

        Route::bind('item', function (string $value, $route): CartItem {
            /** @var Cart $cart */
            $cart = $route->parameter('cart');

            return CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('id', $value)
                ->firstOrFail();
        });
    }
}
