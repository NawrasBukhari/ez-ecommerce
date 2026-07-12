<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('commerce_subscriptions')->cascadeOnDelete();
            $table->string('purchasable_type');
            $table->unsignedBigInteger('purchasable_id');
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->index(['purchasable_type', 'purchasable_id'], 'commerce_sub_items_purchasable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_subscription_items');
    }
};
