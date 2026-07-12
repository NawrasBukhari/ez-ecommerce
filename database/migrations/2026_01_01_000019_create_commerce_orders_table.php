<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('customer_id')->constrained('commerce_customers')->restrictOnDelete();
            $table->foreignId('cart_id')->nullable()->constrained('commerce_carts')->restrictOnDelete();
            $table->string('status');
            $table->string('payment_status');
            $table->string('fulfillment_status');
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_total_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('shipping_total_minor')->default(0);
            $table->bigInteger('fee_total_minor')->default(0);
            $table->bigInteger('grand_total_minor')->default(0);
            $table->bigInteger('refunded_total_minor')->default(0);
            $table->string('shipping_method')->nullable();
            $table->string('payment_method')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'payment_status', 'fulfillment_status']);
        });

        Schema::table('commerce_inventory_reservations', function (Blueprint $table) {
            $table->foreign('cart_id')
                ->references('id')
                ->on('commerce_carts')
                ->restrictOnDelete();
            $table->foreign('order_id')
                ->references('id')
                ->on('commerce_orders')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_inventory_reservations', function (Blueprint $table) {
            $table->dropForeign(['cart_id']);
            $table->dropForeign(['order_id']);
        });

        Schema::dropIfExists('commerce_orders');
    }
};
