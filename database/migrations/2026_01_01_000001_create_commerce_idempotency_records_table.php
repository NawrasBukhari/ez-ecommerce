<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->string('scope');
            $table->string('key');
            $table->string('request_hash');
            $table->string('status');
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->text('last_error')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'key']);
            $table->index(['resource_type', 'resource_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_idempotency_records');
    }
};
