<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_category_product', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained('commerce_categories')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('commerce_products')->restrictOnDelete();
            $table->timestamps();

            $table->primary(['category_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_category_product');
    }
};
