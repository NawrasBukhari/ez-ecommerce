<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_payments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_id')->constrained('commerce_orders')->restrictOnDelete();
            $table->string('gateway');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status');
            $table->unsignedBigInteger('authorized_minor')->default(0);
            $table->unsignedBigInteger('captured_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_payments');
    }
};
