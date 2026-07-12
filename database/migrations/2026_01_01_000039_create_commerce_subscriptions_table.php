<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('customer_id')->constrained('commerce_customers')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('commerce_subscription_plans')->restrictOnDelete();
            $table->string('status');
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end');
            $table->string('payment_method')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_subscriptions');
    }
};
