<?php

use EzEcommerce\Api\Http\Controllers\V1\CartController;
use EzEcommerce\Api\Http\Controllers\V1\CheckoutController;
use EzEcommerce\Api\Http\Controllers\V1\CompanyController;
use EzEcommerce\Api\Http\Controllers\V1\OrderController;
use EzEcommerce\Api\Http\Controllers\V1\ProductController;
use EzEcommerce\Api\Http\Controllers\V1\StoreController;
use EzEcommerce\Api\Http\Controllers\V1\SubscriptionController;
use EzEcommerce\Api\Http\Controllers\V1\VendorController;
use EzEcommerce\Api\Http\Middleware\CommerceApiToken;
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
            Route::delete('cart/{cart}/discount', [CartController::class, 'removeDiscount']);
            Route::post('cart/{cart}/calculate', [CartController::class, 'calculate']);
        });

        Route::post('checkout', [CheckoutController::class, 'store']);

        Route::middleware(CommerceApiToken::class)->group(function (): void {
            Route::get('orders/{order}', [OrderController::class, 'show']);
            Route::post('orders/{order}/capture', [OrderController::class, 'capture']);
            Route::post('orders/{order}/fulfill', [OrderController::class, 'fulfill']);
            Route::post('orders/{order}/refund', [OrderController::class, 'refund']);

            Route::get('stores', [StoreController::class, 'index']);
            Route::post('stores', [StoreController::class, 'store']);
            Route::get('stores/{store}', [StoreController::class, 'show']);

            Route::get('companies', [CompanyController::class, 'index']);
            Route::post('companies', [CompanyController::class, 'store']);
            Route::get('companies/{company}', [CompanyController::class, 'show']);

            Route::get('vendors', [VendorController::class, 'index']);
            Route::post('vendors', [VendorController::class, 'store']);
            Route::get('vendors/{vendor}', [VendorController::class, 'show']);

            Route::get('subscriptions', [SubscriptionController::class, 'index']);
            Route::post('subscriptions', [SubscriptionController::class, 'store']);
            Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show']);
        });
    });
