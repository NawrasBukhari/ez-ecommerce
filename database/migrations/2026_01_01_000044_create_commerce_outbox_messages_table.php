<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('event');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['processed_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_outbox_messages');
    }
};
