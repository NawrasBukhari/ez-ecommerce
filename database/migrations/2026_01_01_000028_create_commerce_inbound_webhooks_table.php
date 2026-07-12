<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_inbound_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('gateway');
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->string('signature')->nullable();
            $table->string('status');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['gateway', 'status']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_inbound_webhooks');
    }
};
