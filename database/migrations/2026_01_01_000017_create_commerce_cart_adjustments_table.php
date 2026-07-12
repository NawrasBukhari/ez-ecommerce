<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_cart_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('commerce_carts')->cascadeOnDelete();
            $table->foreignId('cart_item_id')->nullable()->constrained('commerce_cart_items')->cascadeOnDelete();
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
        Schema::dropIfExists('commerce_cart_adjustments');
    }
};
