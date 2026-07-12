<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_vendor_commissions', function (Blueprint $table) {
            $table->foreignId('payout_id')
                ->nullable()
                ->after('status')
                ->constrained('commerce_vendor_payouts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_vendor_commissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_id');
        });
    }
};
