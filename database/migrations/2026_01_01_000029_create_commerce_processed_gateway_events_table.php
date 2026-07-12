<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_processed_gateway_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway');
            $table->string('external_event_id');
            $table->string('event_type');
            $table->json('payload');
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->unique(['gateway', 'external_event_id'], 'commerce_gateway_events_dedup_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_processed_gateway_events');
    }
};
