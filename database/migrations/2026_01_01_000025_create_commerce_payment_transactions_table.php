<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('commerce_payments')->restrictOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('commerce_payment_attempts')->restrictOnDelete();
            $table->string('type');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('external_id')->nullable();
            $table->string('status');
            $table->timestamp('processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'type']);
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_payment_transactions');
    }
};
