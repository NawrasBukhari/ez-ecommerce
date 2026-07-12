<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_processed_gateway_events', function (Blueprint $table) {
            $table->string('status')->default('processed')->after('event_type');
            $table->text('last_error')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('commerce_processed_gateway_events', function (Blueprint $table) {
            $table->dropColumn(['status', 'last_error']);
        });
    }
};
