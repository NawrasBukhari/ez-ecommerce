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
        // Guard: the constraint may have been manually dropped (e.g. by the
        // dedupe test harness). dropUnique throws on MySQL/PG if it's already
        // gone, so check first via hasIndex on the schema builder.
        if (Schema::hasTable('commerce_payment_transactions')
            && Schema::hasIndex('commerce_payment_transactions', 'commerce_payment_transactions_external_unique')
        ) {
            Schema::table('commerce_payment_transactions', function (Blueprint $table) {
                $table->dropUnique('commerce_payment_transactions_external_unique');
            });
        }
    }
};
