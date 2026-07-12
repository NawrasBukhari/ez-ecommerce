<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('commerce_warehouses')->restrictOnDelete();
            $table->string('stockable_type');
            $table->unsignedBigInteger('stockable_id');
            $table->unsignedBigInteger('on_hand')->default(0);
            $table->unsignedBigInteger('reserved')->default(0);
            $table->timestamps();

            $table->unique(['warehouse_id', 'stockable_type', 'stockable_id'], 'commerce_inventory_balances_stockable_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_inventory_balances');
    }
};
