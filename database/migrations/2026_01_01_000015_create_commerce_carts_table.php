<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_carts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('customer_id')->nullable()->constrained('commerce_customers')->restrictOnDelete();
            $table->string('guest_token_hash')->nullable()->unique();
            $table->string('status');
            $table->char('currency', 3);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_total_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('shipping_total_minor')->default(0);
            $table->bigInteger('fee_total_minor')->default(0);
            $table->bigInteger('grand_total_minor')->default(0);
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_carts');
    }
};
