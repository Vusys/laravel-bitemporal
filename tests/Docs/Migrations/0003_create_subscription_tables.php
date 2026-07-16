<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Vusys\Bitemporal\Tests\Docs\Models\Account;

// $table left untyped — see the note in 0001_create_insurance_tables.php.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function ($table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Mirrors docs/17-example-subscriptions.md — the Subscription fact,
        // varying by billing region.
        Schema::create('subscriptions', function ($table): void {
            $table->id();
            $table->bitemporalForeignFor(Account::class);   // account_id

            $table->string('region');                        // 'EU' | 'US' — the dimension
            $table->string('plan');
            $table->integer('seats');

            $table->bitemporalPeriods();
            $table->timestamps();

            $table->preventBitemporalOverlaps(['account_id'], ['region']);
        });

        Schema::create('plans', function ($table): void {
            $table->id();
            $table->string('tier');
            $table->timestamps();
        });

        Schema::create('features', function ($table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Temporal pivot for plan <-> feature grants. The pivot itself carries
        // the bitemporal periods; each version is its own row.
        Schema::create('plan_feature', function ($table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('feature_id')->constrained()->restrictOnDelete();

            $table->bitemporalPeriods();
            $table->timestamps();

            $table->preventBitemporalOverlaps(['plan_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_feature');
        Schema::dropIfExists('features');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('accounts');
    }
};
