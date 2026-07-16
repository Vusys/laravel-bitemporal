<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Vusys\Bitemporal\Tests\Docs\Models\TaxJurisdiction;

// $table left untyped — see the note in 0001_create_insurance_tables.php.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_jurisdictions', function ($table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Mirrors docs/18-example-tax.md — the tax_rates migration.
        Schema::create('tax_rates', function ($table): void {
            $table->id();
            $table->bitemporalForeignFor(TaxJurisdiction::class);

            $table->string('category');              // 'standard' | 'reduced' | 'zero' — the dimension
            $table->decimal('rate', 5, 4);           // e.g. 0.2000 for 20%

            $table->bitemporalPeriods();
            $table->timestamps();

            $table->preventBitemporalOverlaps(['tax_jurisdiction_id'], ['category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tax_jurisdictions');
    }
};
