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
        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();

            // FK to apps.id
            $table->foreignId('app_id')->constrained('apps')->onDelete('cascade');

            // FK to pricing_plans.id
            $table->foreignId('plan_id')->constrained('pricing_plans')->onDelete('cascade');

            // FK to feature_definitions.id
            $table->foreignId('feature_id')->constrained('feature_definitions')->onDelete('cascade');

            // Feature value (stored as string, parsed based on valueType)
            $table->string('value'); // e.g., "5", "true", "unlimited", "100"

            $table->timestamps();

            // Unique constraint - one feature per plan
            $table->unique(['plan_id', 'feature_id']);

            // Indexes
            $table->index('plan_id');
            $table->index('feature_id');
            $table->index('app_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
