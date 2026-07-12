<?php

use EzEcommerce\Core\Enums\CheckoutStatus;
use EzEcommerce\Core\Enums\SubscriptionInterval;
use EzEcommerce\Core\Models\OutboxMessage;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Discounts\Models\Discount;
use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Marketplace\Models\Vendor;
use EzEcommerce\Marketplace\Models\VendorCommission;
use EzEcommerce\Payments\Actions\ReconcilePayment;
use EzEcommerce\Payments\Contracts\PaymentGateway;
use EzEcommerce\Payments\Data\GatewayWebhookEvent;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Drivers\FakePaymentGateway;
use EzEcommerce\Subscriptions\Actions\CreateSubscription;
use EzEcommerce\Subscriptions\Actions\RenewSubscription;
use EzEcommerce\Subscriptions\Models\SubscriptionPlan;
use EzEcommerce\Tests\Support\SetsUpCatalog;
use EzEcommerce\Webhooks\Outbound\Actions\DispatchWebhook;
use EzEcommerce\Webhooks\Outbound\Jobs\DeliverWebhookJob;
use Illuminate\Support\Facades\Queue;

uses(SetsUpCatalog::class);

it('dispatches outbound webhook to outbox and queue', function () {
    Queue::fake();
    config()->set('ez-ecommerce.outbound_webhooks.endpoints', [
        ['url' => 'https://example.com/hook', 'events' => ['order.placed']],
    ]);

    app(DispatchWebhook::class)->execute('order.placed', ['order_id' => 1]);

    expect(OutboxMessage::query()->where('event', 'order.placed')->exists())->toBeTrue();
    Queue::assertPushed(DeliverWebhookJob::class);
});

it('reconciles fake gateway webhook', function () {
    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: new GatewayWebhookEvent(
            eventType: 'payment_intent.succeeded',
            eventId: 'evt_test_1',
            paymentReference: 'manual_attempt',
            transactionReference: 'txn_test_1',
        ),
    ));

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 1000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'webhook-'.uniqid());

    $attempt = $result->payment->attempts()->first();
    $attempt?->update(['external_id' => 'manual_attempt']);

    $event = app(ReconcilePayment::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"payment_intent.succeeded","id":"evt_test_1"}',
    ));

    expect($event->eventType)->toBe('payment_intent.succeeded');
});

it('accepts inbound webhook route', function () {
    $this->postJson('/api/ez-commerce/v1/webhooks/fake', [
        'type' => 'payment.captured',
        'id' => 'evt_route_1',
    ])->assertOk()->assertJson(['received' => true]);
});

it('removes discount via cart manager', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000);

    Discount::query()->create([
        'code' => 'RM10',
        'type' => 'percent',
        'value' => 10,
        'is_active' => true,
    ]);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->applyDiscount($cart, 'RM10');
    $cart = EzEcommerce::cart()->removeDiscount($cart, 'RM10');

    expect($cart->adjustments)->toHaveCount(0);
});

it('rejects expired discount code', function () {
    Discount::query()->create([
        'code' => 'OLD',
        'type' => 'percent',
        'value' => 10,
        'is_active' => true,
        'valid_to' => now()->subDay(),
    ]);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');

    EzEcommerce::cart()->applyDiscount($cart, 'OLD');
})->throws(RuntimeException::class);

it('renews subscription and bills period', function () {
    $customer = Customer::query()->create(['email' => 'renew@example.com']);
    $plan = SubscriptionPlan::query()->create([
        'name' => 'Billable',
        'interval' => SubscriptionInterval::Month,
        'interval_count' => 1,
        'amount_minor' => 2500,
        'currency' => 'AED',
    ]);

    $subscription = app(CreateSubscription::class)->execute($customer, $plan);
    $subscription->update(['current_period_end' => now()->subMinute()->toImmutable()]);

    $renewed = app(RenewSubscription::class)->execute($subscription->fresh());

    expect($renewed->current_period_end)->toBeGreaterThan(now());
    expect($renewed->metadata?->offsetGet('last_billed_order_id'))->not->toBeNull();
});

it('records marketplace commission on order', function () {
    $vendor = Vendor::query()->create([
        'name' => 'Vendor',
        'slug' => 'vendor',
        'commission_rate' => 0.1,
    ]);

    ['variant' => $variant, 'product' => $product] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    $product->update(['vendor_id' => $vendor->id]);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');

    $result = placeCheckoutOrder($cart, 'commission-'.uniqid());

    expect(VendorCommission::query()
        ->where('order_id', $result->order->id)
        ->exists())->toBeTrue();
});

it('releases expired reservations via manager', function () {
    $count = EzEcommerce::inventory()->releaseExpiredReservations();
    expect($count)->toBeInt();
});

it('uses fake payment gateway for requires action session', function () {
    config()->set('ez-ecommerce.drivers.payment.default', 'fake');
    $this->app->bind(PaymentGateway::class, fn () => FakePaymentGateway::requiresAction());

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 1000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');

    $result = placeCheckoutOrder($cart, 'fake-'.uniqid(), 'flat', 'fake');

    expect($result->status)->toBe(CheckoutStatus::RequiresAction);
    expect($result->requiresCustomerAction())->toBeTrue();
});
