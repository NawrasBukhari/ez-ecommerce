<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_customers', function (Blueprint $table) {
            $table->foreignId('customer_group_id')
                ->nullable()
                ->after('company_id')
                ->constrained('commerce_customer_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_customers', function (Blueprint $table) {
            $table->dropForeign(['customer_group_id']);
            $table->dropColumn('customer_group_id');
        });
    }
};
