<?php

namespace EzEcommerce\Api;

use EzEcommerce\Api\Http\Middleware\GuestCartToken;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartItem;
use EzEcommerce\Catalog\Models\Product;
use EzEcommerce\Orders\Models\Order;
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

        $router = $this->app['router'];
        $router->aliasMiddleware('guest.cart', GuestCartToken::class);
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
