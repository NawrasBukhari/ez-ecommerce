<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('commerce_returns')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('commerce_order_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->boolean('restock')->default(false);
            $table->boolean('damaged')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_return_items');
    }
};
