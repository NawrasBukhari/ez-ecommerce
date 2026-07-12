<?php

use EzEcommerce\Api\Http\Controllers\V1\CartController;
use EzEcommerce\Api\Http\Controllers\V1\CheckoutController;
use EzEcommerce\Api\Http\Controllers\V1\OrderController;
use EzEcommerce\Api\Http\Controllers\V1\ProductController;
use EzEcommerce\Api\Http\Middleware\GuestCartToken;
use Illuminate\Support\Facades\Route;

Route::prefix(config('ez-ecommerce.api.prefix', 'api/ez-commerce/v1'))
    ->middleware(config('ez-ecommerce.api.middleware', ['api']))
    ->group(function (): void {
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{product}', [ProductController::class, 'show']);
        Route::get('products/{product}/variants', [ProductController::class, 'variants']);

        Route::post('cart/guest', [CartController::class, 'storeGuest']);

        Route::middleware(GuestCartToken::class)->group(function (): void {
            Route::get('cart/{cart}', [CartController::class, 'show']);
            Route::post('cart/{cart}/items', [CartController::class, 'storeItem']);
            Route::patch('cart/{cart}/items/{item}', [CartController::class, 'updateItem']);
            Route::delete('cart/{cart}/items/{item}', [CartController::class, 'destroyItem']);
            Route::post('cart/{cart}/discount', [CartController::class, 'applyDiscount']);
            Route::post('cart/{cart}/calculate', [CartController::class, 'calculate']);
        });

        Route::post('checkout', [CheckoutController::class, 'store']);

        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::post('orders/{order}/capture', [OrderController::class, 'capture']);
        Route::post('orders/{order}/fulfill', [OrderController::class, 'fulfill']);
        Route::post('orders/{order}/refund', [OrderController::class, 'refund']);
    });
