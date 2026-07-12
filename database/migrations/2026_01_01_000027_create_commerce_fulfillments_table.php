<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_id')->constrained('commerce_orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('commerce_order_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_fulfillments');
    }
};
