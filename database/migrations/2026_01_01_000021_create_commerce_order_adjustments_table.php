<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_order_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('commerce_orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('commerce_order_items')->restrictOnDelete();
            $table->string('type');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('code')->nullable();
            $table->string('label')->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('origin');
            $table->boolean('included_in_unit_price')->default(false);
            $table->boolean('affects_total')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_order_adjustments');
    }
};
