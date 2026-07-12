<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('commerce_payments')->restrictOnDelete();
            $table->string('operation');
            $table->string('idempotency_key');
            $table->string('status');
            $table->string('external_id')->nullable();
            $table->text('redirect_url')->nullable();
            $table->string('client_secret')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('request_metadata')->nullable();
            $table->json('response_metadata')->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'idempotency_key']);
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_payment_attempts');
    }
};
