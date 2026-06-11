<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function connection(): ?string
    {
        $connection = config('bitemporal.audit_log.connection');

        return is_string($connection) ? $connection : null;
    }

    public function up(): void
    {
        $table = config('bitemporal.audit_log.table', 'temporal_audit_log');
        $table = is_string($table) ? $table : 'temporal_audit_log';

        Schema::connection($this->connection())->create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('event_class', 191);
            $table->string('model', 191);
            $table->string('entity_type', 191)->nullable();
            $table->string('entity_id', 191);
            $table->json('dimensions');
            $table->json('payload');
            $table->timestamp('recorded_at', 6);
            $table->timestamp('observed_at', 6);

            $table->index(['model', 'entity_type', 'entity_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        $table = config('bitemporal.audit_log.table', 'temporal_audit_log');
        $table = is_string($table) ? $table : 'temporal_audit_log';

        Schema::connection($this->connection())->dropIfExists($table);
    }
};
