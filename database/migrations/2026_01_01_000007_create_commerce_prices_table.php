<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_prices', function (Blueprint $table) {
            $table->id();
            $table->string('priceable_type');
            $table->unsignedBigInteger('priceable_id');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('type');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('customer_group_id')->nullable();
            $table->foreignId('price_list_id')->nullable()->constrained('commerce_price_lists')->restrictOnDelete();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->timestamps();

            $table->index(['priceable_type', 'priceable_id']);
            $table->index(['type', 'currency']);
            $table->index('customer_id');
            $table->index('customer_group_id');
            $table->index(['valid_from', 'valid_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_prices');
    }
};
