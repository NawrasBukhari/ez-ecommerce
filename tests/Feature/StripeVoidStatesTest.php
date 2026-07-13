<?php

use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Payments\Data\VoidPaymentData;
use EzEcommerce\Payments\Drivers\StripePaymentGateway;
use EzEcommerce\Payments\Exceptions\PaymentOperationNotSupported;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;

function makeGatewayWithIntent(string $intentStatus, ?string $cancelledStatus = null): StripePaymentGateway
{
    config()->set('ez-ecommerce.drivers.payment.stripe.secret', 'test_secret');

    // Create without constructor (Stripe SDK may not be installed in test env).
    $ref = new \ReflectionClass(StripePaymentGateway::class);
    $gateway = $ref->newInstanceWithoutConstructor();

    // Build a stub PaymentIntent object with the properties the void() method reads.
    $intent = new class($intentStatus)
    {
        public string $status;

        public string $id = 'pi_test_123';

        public function __construct(string $status)
        {
            $this->status = $status;
        }
    };

    $cancelledIntent = new class($cancelledStatus ?? 'canceled')
    {
        public string $status;

        public string $id = 'pi_test_123';

        public function __construct(string $status)
        {
            $this->status = $status;
        }
    };

    $paymentIntents = new class($intent, $cancelledIntent)
    {
        private $retrieveIntent;

        private $cancelledIntent;

        public function __construct($intent, $cancelledIntent)
        {
            $this->retrieveIntent = $intent;
            $this->cancelledIntent = $cancelledIntent;
        }

        public function retrieve(string $id)
        {
            return $this->retrieveIntent;
        }

        public function cancel(string $id, array $params = [], array $options = [])
        {
            return $this->cancelledIntent;
        }
    };

    $client = new class($paymentIntents)
    {
        private $paymentIntents;

        public function __construct($paymentIntents)
        {
            $this->paymentIntents = $paymentIntents;
        }

        public function __get(string $name)
        {
            if ($name === 'paymentIntents') {
                return $this->paymentIntents;
            }

            throw new \RuntimeException("Undefined property: $name");
        }
    };

    // Inject the stub client via reflection.
    $prop = $ref->getProperty('client');
    $prop->setValue($gateway, $client);

    return $gateway;
}

function makeVoidData(): array
{
    $payment = Payment::query()->make([
        'amount_minor' => 10000,
        'currency' => 'AED',
        'public_id' => '01TESTVOIDPAY'.uniqid(),
        'metadata' => ['stripe_payment_intent_id' => 'pi_test_123'],
    ]);

    $attempt = PaymentAttempt::query()->make([
        'public_id' => '01TESTVOIDATT'.uniqid(),
        'idempotency_key' => 'void-'.uniqid(),
    ]);

    $data = new VoidPaymentData(
        payment: $payment,
        attempt: $attempt,
        amount: Money::fromMinor(10000, 'AED'),
        providerReference: 'pi_test_123',
    );

    return [$data, $payment];
}

it('voids a requires_capture PaymentIntent', function () {
    [$data, $payment] = makeVoidData();
    $gateway = makeGatewayWithIntent('requires_capture', 'canceled');

    $result = $gateway->void($data);

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Cancelled);
})->group('hardening');

it('voids a requires_action PaymentIntent', function () {
    [$data, $payment] = makeVoidData();
    $gateway = makeGatewayWithIntent('requires_action', 'canceled');

    $result = $gateway->void($data);

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Cancelled);
})->group('hardening');

it('voids a requires_payment_method PaymentIntent', function () {
    [$data, $payment] = makeVoidData();
    $gateway = makeGatewayWithIntent('requires_payment_method', 'canceled');

    $result = $gateway->void($data);

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Cancelled);
})->group('hardening');

it('voids a requires_confirmation PaymentIntent', function () {
    [$data, $payment] = makeVoidData();
    $gateway = makeGatewayWithIntent('requires_confirmation', 'canceled');

    $result = $gateway->void($data);

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Cancelled);
})->group('hardening');

it('treats an already-cancelled PaymentIntent as idempotent success', function () {
    [$data, $payment] = makeVoidData();
    // The retrieve returns an already-cancelled intent; cancel() is never called.
    $gateway = makeGatewayWithIntent('canceled', 'canceled');

    $result = $gateway->void($data);

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Cancelled);
})->group('hardening');

it('rejects void on a succeeded PaymentIntent', function () {
    [$data, $payment] = makeVoidData();
    $gateway = makeGatewayWithIntent('succeeded', 'canceled');

    expect(fn () => $gateway->void($data))->toThrow(PaymentOperationNotSupported::class);
})->group('hardening');
