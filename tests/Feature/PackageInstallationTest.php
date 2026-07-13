<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guards the package-installation release blocker: migrations and commands
 * must be registered so a host running `php artisan migrate` gets every table
 * and column, and `php artisan list` shows every command. The base TestCase
 * loads migrations directly via loadMigrationsFrom, which mirrors what
 * Spatie's runsMigrations() does on a real host; the assertions below verify
 * the schema and command surface that a clean host would observe.
 */
it('creates all core commerce tables', function () {
    expect(Schema::hasTable('commerce_orders'))->toBeTrue()
        ->and(Schema::hasTable('commerce_payments'))->toBeTrue()
        ->and(Schema::hasTable('commerce_payment_transactions'))->toBeTrue()
        ->and(Schema::hasTable('commerce_fulfillments'))->toBeTrue()
        ->and(Schema::hasTable('commerce_outbox_messages'))->toBeTrue()
        ->and(Schema::hasTable('commerce_processed_gateway_events'))->toBeTrue()
        ->and(Schema::hasTable('commerce_inventory_reservations'))->toBeTrue()
        ->and(Schema::hasTable('commerce_carts'))->toBeTrue()
        ->and(Schema::hasTable('commerce_products'))->toBeTrue()
        ->and(Schema::hasTable('commerce_product_variants'))->toBeTrue();
})->group('hardening');

it('creates the newest migration columns and unique indexes', function () {
    expect(Schema::hasColumn('commerce_outbox_messages', 'key'))->toBeTrue()
        ->and(Schema::hasColumn('commerce_fulfillments', 'idempotency_key'))->toBeTrue()
        ->and(Schema::hasColumn('commerce_payment_transactions', 'external_id'))->toBeTrue();

    // Verify the outbox key unique index by attempting a duplicate insert.
    $base = ['event' => 'order.paid', 'key' => 'order.paid:dup-check', 'payload' => '{}'];
    DB::table('commerce_outbox_messages')->insert($base);
    expect(fn () => DB::table('commerce_outbox_messages')->insert($base))
        ->toThrow(Exception::class);
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
        ->and($commands)->toContain('commerce:process-outbox');
})->group('hardening');
