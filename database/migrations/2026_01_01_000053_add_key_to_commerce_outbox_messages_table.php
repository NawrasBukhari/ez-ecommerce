<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_outbox_messages', function (Blueprint $table) {
            $table->string('key')->nullable()->after('event');
        });

        // Build a unique index only when no duplicate keys exist; fresh installs always satisfy this.
        // ponytail: Upgrades that accumulated duplicate order.paid:{order_id} rows before this
        // migration must run commerce:dedupe-transactions first; the index is created unconditionally
        // because the outbox was previously append-only with no deterministic key.
        Schema::table('commerce_outbox_messages', function (Blueprint $table) {
            $table->unique('key');
        });
    }

    public function down(): void
    {
        Schema::table('commerce_outbox_messages', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->dropColumn('key');
        });
    }
};