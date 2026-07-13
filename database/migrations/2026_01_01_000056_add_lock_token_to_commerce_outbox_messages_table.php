<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_outbox_messages', function (Blueprint $table) {
            $table->string('lock_token')->nullable()->after('locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('commerce_outbox_messages', function (Blueprint $table) {
            $table->dropColumn('lock_token');
        });
    }
};
