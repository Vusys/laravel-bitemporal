<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('amount')->nullable();
            $table->string('currency', 3)->nullable();
            $table->dateTime('valid_from', 6);
            $table->dateTime('valid_to', 6)->nullable();
            $table->dateTime('recorded_from', 6);
            $table->dateTime('recorded_to', 6)->nullable();
            $table->boolean('is_retraction')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_versions');
    }
};
