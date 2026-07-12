<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_customer_groups', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name');
            $table->string('code')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::table('commerce_prices', function (Blueprint $table) {
            $table->foreign('customer_group_id')
                ->references('id')
                ->on('commerce_customer_groups')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_prices', function (Blueprint $table) {
            $table->dropForeign(['customer_group_id']);
        });

        Schema::dropIfExists('commerce_customer_groups');
    }
};
