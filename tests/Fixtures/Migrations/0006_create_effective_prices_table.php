<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An effective-dated-only table: valid time only, no recorded spell.
        Schema::create('effective_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('amount')->nullable();
            $table->dateTime('valid_from', 6);
            $table->dateTime('valid_to', 6)->nullable();
            $table->boolean('is_retraction')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('effective_prices');
    }
};
