<?php

use EzEcommerce\Tests\InstallationTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(InstallationTestCase::class);

/**
 * Guards the package-installation release blocker. Migrations are discovered
 * purely via the package's Spatie runsMigrations() path (no loadMigrationsFrom
 * in the test base), mirroring what a real host observes after
 * `php artisan migrate`. Verifies the core schema, newest columns/indexes, the
 * unique payment-transaction external reference, and the full command surface.
 */
it('creates all core commerce tables via package migration discovery', function () {
    expect(Schema::hasTable('commerce_orders'))->toBeTrue()
        ->and(Schema::hasTable('commerce_payments'))->toBeTrue()
        ->and(Schema::hasTable('commerce_payment_attempts'))->toBeTrue()
        ->and(Schema::hasTable('commerce_payment_transactions'))->toBeTrue()
        ->and(Schema::hasTable('commerce_fulfillments'))->toBeTrue()
        ->and(Schema::hasTable('commerce_outbox_messages'))->toBeTrue()
        ->and(Schema::hasTable('commerce_processed_gateway_events'))->toBeTrue()
        ->and(Schema::hasTable('commerce_inventory_reservations'))->toBeTrue()
        ->and(Schema::hasTable('commerce_carts'))->toBeTrue()
        ->and(Schema::hasTable('commerce_products'))->toBeTrue()
        ->and(Schema::hasTable('commerce_product_variants'))->toBeTrue();
})->group('hardening');

it('creates the newest migration columns and indexes', function () {
    expect(Schema::hasColumn('commerce_outbox_messages', 'key'))->toBeTrue()
        ->and(Schema::hasColumn('commerce_outbox_messages', 'status'))->toBeTrue()
        ->and(Schema::hasColumn('commerce_outbox_messages', 'locked_until'))->toBeTrue()
        ->and(Schema::hasColumn('commerce_fulfillments', 'idempotency_key'))->toBeTrue()
        ->and(Schema::hasColumn('commerce_payment_transactions', 'external_id'))->toBeTrue();
})->group('hardening');

it('enforces the outbox key unique index via duplicate-insert rejection', function () {
    $base = ['event' => 'order.paid', 'key' => 'order.paid:dup-check', 'payload' => '{}'];
    DB::table('commerce_outbox_messages')->insert($base);

    expect(fn () => DB::table('commerce_outbox_messages')->insert($base))
        ->toThrow(Exception::class);
})->group('hardening');

it('enforces the unique payment-transaction external reference via duplicate-insert rejection', function () {
    // Driver-agnostic: a duplicate (payment_id, type, external_id) succeeded
    // row must be rejected by the unique index. The installation base disables
    // FKs on SQLite via config; on MySQL/PG we disable them explicitly here so
    // we can insert with payment_id=1/attempt_id=1 without standing up a full
    // order/payment/attempt graph. This isolates the unique-index behaviour.
    Schema::disableForeignKeyConstraints();

    try {
        $row = [
            'payment_id' => 1,
            'attempt_id' => 1,
            'type' => 'capture',
            'amount_minor' => 100,
            'currency' => 'AED',
            'external_id' => 'ext_dup_check',
            'status' => 'succeeded',
            'processed_at' => now(),
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('commerce_payment_transactions')->insert($row);

        expect(fn () => DB::table('commerce_payment_transactions')->insert($row))
            ->toThrow(Exception::class);
    } finally {
        Schema::enableForeignKeyConstraints();
    }
})->group('hardening');

it('registers all package artisan commands', function () {
    $commands = collect(Artisan::all())->keys()->toArray();

    expect($commands)->toContain('commerce:install')
        ->and($commands)->toContain('commerce:purge-expired-carts')
        ->and($commands)->toContain('commerce:purge-idempotency-records')
        ->and($commands)->toContain('commerce:release-expired-reservations')
        ->and($commands)->toContain('commerce:renew-subscriptions')
        ->and($commands)->toContain('commerce:reconcile-payments')
        ->and($commands)->toContain('commerce:reconcile-refunds')
        ->and($commands)->toContain('commerce:reconcile-finalizations')
        ->and($commands)->toContain('commerce:reconcile-voids')
        ->and($commands)->toContain('commerce:dedupe-transactions')
        ->and($commands)->toContain('commerce:process-outbox')
        ->and($commands)->toContain('commerce:replay-webhooks');
})->group('hardening');
