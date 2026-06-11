<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporal_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 191);
            $table->string('model', 191);
            $table->string('entity_type', 191)->nullable();
            $table->string('entity_id', 191);
            $table->string('operation', 32);
            $table->char('parameters_hash', 64);
            $table->json('result_snapshot');
            $table->timestamp('created_at', 6);

            $table->unique(['model', 'entity_type', 'entity_id', 'key']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporal_idempotency_keys');
    }
};
