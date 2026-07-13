<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_outbox_messages', function (Blueprint $table) {
            $table->timestamp('available_at')->nullable()->after('status');
            $table->timestamp('locked_at')->nullable()->after('available_at');
            $table->timestamp('locked_until')->nullable()->after('locked_at');
            $table->unsignedInteger('attempts')->default(0)->after('locked_until');
            $table->text('last_error')->nullable()->after('attempts');
        });

        Schema::table('commerce_outbox_messages', function (Blueprint $table) {
            $table->index(['status', 'available_at']);
            $table->index(['status', 'locked_until']);
        });
    }

    public function down(): void
    {
        Schema::table('commerce_outbox_messages', function (Blueprint $table) {
            $table->dropIndex(['status', 'locked_until']);
            $table->dropIndex(['status', 'available_at']);
        });

        Schema::table('commerce_outbox_messages', function (Blueprint $table) {
            $table->dropColumn(['available_at', 'locked_at', 'locked_until', 'attempts', 'last_error']);
        });
    }
};
