<?php

use EzEcommerce\Core\Enums\FulfillmentStatus;
use EzEcommerce\Core\Enums\OrderPaymentStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function createDedupePayment(int $amountMinor = 10000, PaymentStatus $status = PaymentStatus::Captured): Payment
{
    $customer = Customer::query()->create([
        'public_id' => '01DDPCUST'.uniqid(),
        'email' => 'ddp'.uniqid().'@example.com',
    ]);

    $order = Order::query()->create([
        'public_id' => '01DDPORD'.uniqid(),
        'customer_id' => $customer->id,
        'status' => OrderStatus::PendingPayment,
        'payment_status' => 'unpaid',
        'fulfillment_status' => FulfillmentStatus::Unfulfilled,
        'currency' => 'AED',
        'subtotal_minor' => $amountMinor,
        'discount_total_minor' => 0,
        'tax_total_minor' => 0,
        'shipping_total_minor' => 0,
        'fee_total_minor' => 0,
        'grand_total_minor' => $amountMinor,
        'refunded_total_minor' => 0,
        'payment_method' => 'fake',
    ]);

    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'gateway' => 'fake',
        'amount_minor' => $amountMinor,
        'currency' => 'AED',
        'status' => $status,
        'authorized_minor' => 0,
        'captured_minor' => 0,
        'refunded_minor' => 0,
        'public_id' => '01DDPPAY'.uniqid(),
    ]);

    PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'create_session',
        'idempotency_key' => 'session:'.$payment->public_id,
        'status' => 'succeeded',
        'external_id' => 'fake_session_'.$payment->public_id,
    ]);

    return $payment;
}

function dropTxnUniqueIndex(): void
{
    DB::statement('DROP INDEX IF EXISTS commerce_payment_transactions_external_unique');
}

it('excludes authorization transactions from captured_minor when rebuilding aggregates', function () {
    $payment = createDedupePayment(10000, PaymentStatus::Authorized);
    dropTxnUniqueIndex();

    PaymentTransaction::query()->create([
        'payment_id' => $payment->id,
        'type' => PaymentTransactionType::Authorization,
        'amount_minor' => 10000,
        'currency' => 'AED',
        'external_id' => 'auth_ddp_'.uniqid(),
        'status' => 'succeeded',
    ]);
    PaymentTransaction::query()->create([
        'payment_id' => $payment->id,
        'type' => PaymentTransactionType::Capture,
        'amount_minor' => 5000,
        'currency' => 'AED',
        'external_id' => 'cap_ddp_'.uniqid(),
        'status' => 'succeeded',
    ]);

    // Corrupt the aggregate to the old buggy value (auth + capture).
    $payment->update(['captured_minor' => 15000]);

    Artisan::call('commerce:dedupe-transactions', ['--payment' => $payment->id]);

    $payment->refresh();
    expect($payment->captured_minor)->toBe(5000)
        ->and($payment->authorized_minor)->toBe(10000)
        ->and($payment->status)->toBe(PaymentStatus::PartiallyCaptured);
})->group('hardening');

it('derives status with reversed precedence over captured and refunded', function () {
    $payment = createDedupePayment(10000, PaymentStatus::Captured);
    dropTxnUniqueIndex();

    PaymentTransaction::query()->create([
        'payment_id' => $payment->id,
        'type' => PaymentTransactionType::Capture,
        'amount_minor' => 10000,
        'currency' => 'AED',
        'external_id' => 'cap_prec_'.uniqid(),
        'status' => 'succeeded',
    ]);
    PaymentTransaction::query()->create([
        'payment_id' => $payment->id,
        'type' => PaymentTransactionType::Reversal,
        'amount_minor' => 10000,
        'currency' => 'AED',
        'external_id' => 'rev_prec_'.uniqid(),
        'status' => 'succeeded',
    ]);

    Artisan::call('commerce:dedupe-transactions', ['--payment' => $payment->id]);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Reversed)
        ->and($payment->fresh()->order->payment_status)->toBe(OrderPaymentStatus::Disputed);
})->group('hardening');

it('derives fully refunded status when refunds meet the captured total', function () {
    $payment = createDedupePayment(10000, PaymentStatus::Captured);
    dropTxnUniqueIndex();

    PaymentTransaction::query()->create([
        'payment_id' => $payment->id,
        'type' => PaymentTransactionType::Capture,
        'amount_minor' => 10000,
        'currency' => 'AED',
        'external_id' => 'cap_ref_'.uniqid(),
        'status' => 'succeeded',
    ]);
    PaymentTransaction::query()->create([
        'payment_id' => $payment->id,
        'type' => PaymentTransactionType::Refund,
        'amount_minor' => 10000,
        'currency' => 'AED',
        'external_id' => 'rfd_ref_'.uniqid(),
        'status' => 'succeeded',
    ]);

    Artisan::call('commerce:dedupe-transactions', ['--payment' => $payment->id]);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
})->group('hardening');

it('does not delete duplicate transactions in dry-run mode', function () {
    $payment = createDedupePayment(10000, PaymentStatus::Captured);
    dropTxnUniqueIndex();

    $externalId = 'cap_dry_'.uniqid();
    PaymentTransaction::query()->create([
        'payment_id' => $payment->id,
        'type' => PaymentTransactionType::Capture,
        'amount_minor' => 10000,
        'currency' => 'AED',
        'external_id' => $externalId,
        'status' => 'succeeded',
    ]);
    PaymentTransaction::query()->create([
        'payment_id' => $payment->id,
        'type' => PaymentTransactionType::Capture,
        'amount_minor' => 10000,
        'currency' => 'AED',
        'external_id' => $externalId,
        'status' => 'succeeded',
    ]);

    Artisan::call('commerce:dedupe-transactions', ['--dry-run' => true]);

    expect(PaymentTransaction::query()
        ->where('payment_id', $payment->id)
        ->where('external_id', $externalId)
        ->count())->toBe(2);
})->group('hardening');

it('preserves a non-ledger terminal status when no ledger signal supersedes it', function () {
    $payment = createDedupePayment(10000, PaymentStatus::Failed);

    Artisan::call('commerce:dedupe-transactions', ['--payment' => $payment->id]);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);
})->group('hardening');
