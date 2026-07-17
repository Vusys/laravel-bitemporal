<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Vusys\Bitemporal\Tests\Docs\Models\Employee;

// $table left untyped — see the note in 0001_create_insurance_tables.php.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function ($table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Mirrors docs/16-example-salary.md — the compensations migration.
        Schema::create('compensations', function ($table): void {
            $table->id();
            $table->bitemporalForeignFor(Employee::class);   // employee_id

            $table->string('component');                     // 'base' | 'bonus' — the dimension
            $table->decimal('annual_amount', 12, 2);

            $table->bitemporalPeriods();
            $table->timestamps();

            $table->preventBitemporalOverlaps(['employee_id'], ['component']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compensations');
        Schema::dropIfExists('employees');
    }
};
