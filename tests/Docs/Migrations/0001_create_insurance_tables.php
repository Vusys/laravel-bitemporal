<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Vusys\Bitemporal\Tests\Docs\Models\Policy;

// The migration closures leave $table untyped (the docs show `Blueprint $table`).
// The temporal Blueprint macros are registered at runtime, and larastan's own
// Blueprint stub shadows this package's Blueprint.stub, so a typed parameter
// reports the macros as undefined under static analysis. The package's own
// BlueprintMacrosTest uses the same untyped style for the same reason.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function ($table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->timestamps();
        });

        // Mirrors docs/15-example-insurance.md — the policy_coverages migration.
        Schema::create('policy_coverages', function ($table): void {
            $table->id();
            $table->bitemporalForeignFor(Policy::class);   // policy_id, FK, restrictOnDelete

            // The docs declare these NOT NULL, but retract() inserts an anti-row
            // with every domain column NULL, so retractions fail against a
            // NOT NULL schema. Anti-row-bearing timelines need nullable value
            // columns.
            $table->decimal('limit', 12, 2)->nullable();
            $table->decimal('deductible', 10, 2)->nullable();
            $table->decimal('premium', 10, 2)->nullable();

            $table->bitemporalPeriods();                    // valid_* + recorded_* (µs) + is_retraction
            $table->timestamps();

            $table->preventBitemporalOverlaps(['policy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_coverages');
        Schema::dropIfExists('policies');
    }
};
