<?php

use EzEcommerce\Api\Http\Controllers\V1\AddressController;
use EzEcommerce\Api\Http\Controllers\V1\CartController;
use EzEcommerce\Api\Http\Controllers\V1\CategoryController;
use EzEcommerce\Api\Http\Controllers\V1\CheckoutController;
use EzEcommerce\Api\Http\Controllers\V1\CompanyController;
use EzEcommerce\Api\Http\Controllers\V1\CustomerController;
use EzEcommerce\Api\Http\Controllers\V1\CustomerGroupController;
use EzEcommerce\Api\Http\Controllers\V1\InventoryController;
use EzEcommerce\Api\Http\Controllers\V1\OrderController;
use EzEcommerce\Api\Http\Controllers\V1\ProcessedGatewayEventController;
use EzEcommerce\Api\Http\Controllers\V1\ProductController;
use EzEcommerce\Api\Http\Controllers\V1\ReturnController;
use EzEcommerce\Api\Http\Controllers\V1\ShippingController;
use EzEcommerce\Api\Http\Controllers\V1\StoreController;
use EzEcommerce\Api\Http\Controllers\V1\SubscriptionController;
use EzEcommerce\Api\Http\Controllers\V1\SubscriptionPlanController;
use EzEcommerce\Api\Http\Controllers\V1\VendorController;
use EzEcommerce\Api\Http\Controllers\V1\WebhookDeliveryController;
use EzEcommerce\Api\Http\Middleware\GuestCartToken;
use EzEcommerce\Api\Http\Middleware\ValidateCheckoutCartAccess;
use Illuminate\Support\Facades\Route;

Route::prefix(config('ez-ecommerce.api.prefix', 'api/ez-commerce/v1'))
    ->middleware(config('ez-ecommerce.api.middleware', ['api']))
    ->group(function (): void {
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{product}', [ProductController::class, 'show']);
        Route::get('products/{product}/variants', [ProductController::class, 'variants']);

        Route::get('categories', [CategoryController::class, 'index']);
        Route::get('categories/{category}', [CategoryController::class, 'show']);
        Route::get('categories/{category}/products', [CategoryController::class, 'products']);

        Route::get('shipping-methods', [ShippingController::class, 'index']);

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

        Route::post('checkout', [CheckoutController::class, 'store'])
            ->middleware(ValidateCheckoutCartAccess::class);

        Route::middleware('commerce.api:catalog.write')->group(function (): void {
            Route::post('products', [ProductController::class, 'store']);
        });

        Route::middleware('commerce.api:inventory.read')->group(function (): void {
            Route::get('warehouses', [InventoryController::class, 'indexWarehouses']);
            Route::get('warehouses/{warehouse}', [InventoryController::class, 'showWarehouse']);
            Route::get('warehouses/{warehouse}/movements', [InventoryController::class, 'movements']);
        });

        Route::middleware('commerce.api:inventory.write')->group(function (): void {
            Route::post('warehouses', [InventoryController::class, 'storeWarehouse']);
            Route::post('warehouses/{warehouse}/receive', [InventoryController::class, 'receiveStock']);
            Route::post('warehouses/{warehouse}/adjust', [InventoryController::class, 'adjustStock']);
            Route::post('warehouses/{warehouse}/transfer', [InventoryController::class, 'transferStock']);
            Route::post('warehouses/{warehouse}/deactivate', [InventoryController::class, 'deactivateWarehouse']);
            Route::post('reservations/{reservation}/release', [InventoryController::class, 'releaseReservation']);
        });

        Route::middleware('commerce.api:customers.read')->group(function (): void {
            Route::get('customers', [CustomerController::class, 'index']);
            Route::get('customers/{customer}', [CustomerController::class, 'show']);
            Route::get('customers/{customer}/addresses', [AddressController::class, 'index']);
            Route::get('customers/{customer}/addresses/{address}', [AddressController::class, 'show']);
            Route::get('customer-groups', [CustomerGroupController::class, 'index']);
            Route::get('customer-groups/{customerGroup}', [CustomerGroupController::class, 'show']);
        });

        Route::middleware('commerce.api:customers.write')->group(function (): void {
            Route::post('customers', [CustomerController::class, 'store']);
            Route::post('customers/{customer}/addresses', [AddressController::class, 'store']);
            Route::post('customers/{customer}/cart', [CustomerController::class, 'storeCart']);
            Route::post('cart/merge', [CartController::class, 'merge']);
            Route::post('customer-groups', [CustomerGroupController::class, 'store']);
        });

        Route::middleware('commerce.api:orders.read')->group(function (): void {
            Route::get('orders/{order}', [OrderController::class, 'show']);
            Route::get('orders/{order}/transitions', [OrderController::class, 'transitions']);
            Route::get('orders/{order}/fulfillments', [OrderController::class, 'fulfillments']);
            Route::get('orders/{order}/refunds', [OrderController::class, 'refunds']);
            Route::get('orders/{order}/payments', [OrderController::class, 'payments']);
            Route::get('orders/{order}/payments/{payment}/transactions', [OrderController::class, 'paymentTransactions']);
            Route::get('inbound-webhooks/events', [ProcessedGatewayEventController::class, 'index']);
        });

        Route::middleware('commerce.api:orders.write')->group(function (): void {
            Route::post('orders/{order}/capture', [OrderController::class, 'capture']);
            Route::post('orders/{order}/fulfill', [OrderController::class, 'fulfill']);
            Route::post('orders/{order}/refund', [OrderController::class, 'refund']);
            Route::post('orders/{order}/retry-payment', [OrderController::class, 'retryPayment']);
            Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
            Route::post('orders/{order}/complete', [OrderController::class, 'complete']);
            Route::post('orders/{order}/returns', [ReturnController::class, 'store']);
            Route::post('webhook-deliveries/{webhookDelivery}/retry', [WebhookDeliveryController::class, 'retry']);
        });

        Route::middleware('commerce.api:returns.read')->group(function (): void {
            Route::get('returns', [ReturnController::class, 'index']);
            Route::get('returns/{return}', [ReturnController::class, 'show']);
        });

        Route::middleware('commerce.api:returns.write')->group(function (): void {
            Route::post('returns/{return}/receive', [ReturnController::class, 'receive']);
            Route::post('returns/{return}/items/{returnItem}/restock', [ReturnController::class, 'restockItem']);
            Route::post('returns/{return}/items/{returnItem}/mark-damaged', [ReturnController::class, 'markItemDamaged']);
        });

        Route::middleware('commerce.api:stores.read')->group(function (): void {
            Route::get('stores', [StoreController::class, 'index']);
            Route::get('stores/{store}', [StoreController::class, 'show']);
        });

        Route::middleware('commerce.api:stores.write')->group(function (): void {
            Route::post('stores', [StoreController::class, 'store']);
        });

        Route::middleware('commerce.api:companies.read')->group(function (): void {
            Route::get('companies', [CompanyController::class, 'index']);
            Route::get('companies/{company}', [CompanyController::class, 'show']);
        });

        Route::middleware('commerce.api:companies.write')->group(function (): void {
            Route::post('companies', [CompanyController::class, 'store']);
        });

        Route::middleware('commerce.api:marketplace.read')->group(function (): void {
            Route::get('vendors', [VendorController::class, 'index']);
            Route::get('vendors/{vendor}', [VendorController::class, 'show']);
            Route::get('vendors/{vendor}/commissions', [VendorController::class, 'commissions']);
            Route::get('vendors/{vendor}/payouts', [VendorController::class, 'payouts']);
            Route::get('vendors/{vendor}/payouts/{payout}', [VendorController::class, 'showPayout']);
        });

        Route::middleware('commerce.api:marketplace.write')->group(function (): void {
            Route::post('vendors', [VendorController::class, 'store']);
            Route::post('vendors/{vendor}/payouts', [VendorController::class, 'payout']);
        });

        Route::middleware('commerce.api:subscriptions.read')->group(function (): void {
            Route::get('subscriptions', [SubscriptionController::class, 'index']);
            Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show']);
            Route::get('subscription-plans', [SubscriptionPlanController::class, 'index']);
            Route::get('subscription-plans/{plan}', [SubscriptionPlanController::class, 'show']);
        });

        Route::middleware('commerce.api:subscriptions.write')->group(function (): void {
            Route::post('subscriptions', [SubscriptionController::class, 'store']);
            Route::post('subscription-plans', [SubscriptionPlanController::class, 'store']);
        });
    });
