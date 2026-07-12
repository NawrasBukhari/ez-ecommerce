<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_vendor_payouts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('vendor_id')->constrained('commerce_vendors')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedInteger('commission_count');
            $table->timestamp('paid_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_vendor_payouts');
    }
};
