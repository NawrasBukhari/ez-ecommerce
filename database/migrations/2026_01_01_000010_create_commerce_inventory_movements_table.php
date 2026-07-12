<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('balance_id')->constrained('commerce_inventory_balances')->restrictOnDelete();
            $table->string('type');
            $table->bigInteger('on_hand_delta');
            $table->bigInteger('reserved_delta');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_scope')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->unique(['idempotency_scope', 'idempotency_key'], 'commerce_inventory_movements_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_inventory_movements');
    }
};
