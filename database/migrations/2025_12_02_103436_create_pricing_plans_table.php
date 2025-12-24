<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();

            // FK to apps.id
            $table->foreignId('app_id')->constrained('apps')->onDelete('cascade');

            // Plan details
            $table->string('name')->unique(); // e.g., "Free", "Basic Monthly", "Pro Annual"
            $table->string('display_name'); // e.g., "Basic Plan"
            $table->decimal('amount', 10, 2); // Price amount
            $table->string('currency_code', 3)->default('USD');
            $table->string('interval'); // "FREE", "EVERY_30_DAYS", "ANNUAL"
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0); // For display ordering

            $table->timestamps();

            // Indexes
            $table->index('is_active');
            $table->index('app_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_plans');
    }
};
