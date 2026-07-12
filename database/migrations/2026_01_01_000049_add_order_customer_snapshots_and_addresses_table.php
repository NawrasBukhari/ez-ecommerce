<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table) {
            $table->string('customer_email')->nullable()->after('customer_id');
            $table->string('customer_name')->nullable()->after('customer_email');
            $table->string('customer_phone')->nullable()->after('customer_name');
        });

        Schema::create('commerce_order_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('commerce_orders')->cascadeOnDelete();
            $table->string('type');
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country_code', 2);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_order_addresses');

        Schema::table('commerce_orders', function (Blueprint $table) {
            $table->dropColumn(['customer_email', 'customer_name', 'customer_phone']);
        });
    }
};
