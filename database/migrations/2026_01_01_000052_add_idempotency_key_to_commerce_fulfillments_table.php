<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_fulfillments', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('quantity');
            $table->unique('idempotency_key', 'commerce_fulfillments_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('commerce_fulfillments', function (Blueprint $table) {
            $table->dropUnique('commerce_fulfillments_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
