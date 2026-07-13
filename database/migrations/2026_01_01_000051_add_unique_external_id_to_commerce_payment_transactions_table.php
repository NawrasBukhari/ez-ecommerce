<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_payment_transactions', function (Blueprint $table) {
            $table->unique(['payment_id', 'type', 'external_id'], 'commerce_payment_transactions_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('commerce_payment_transactions', function (Blueprint $table) {
            $table->dropUnique('commerce_payment_transactions_external_unique');
        });
    }
};
