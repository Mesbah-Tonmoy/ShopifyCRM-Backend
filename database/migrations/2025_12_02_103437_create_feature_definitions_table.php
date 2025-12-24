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
        Schema::create('feature_definitions', function (Blueprint $table) {
            $table->id();

            // FK to apps.id
            $table->foreignId('app_id')->constrained('apps')->onDelete('cascade');

            // Feature details
            $table->string('key')->unique(); // e.g., "max_job_posts", "max_applications"
            $table->string('name'); // e.g., "Maximum Job Posts"
            $table->text('description')->nullable(); // Feature description
            $table->string('value_type'); // "NUMBER", "BOOLEAN", "UNLIMITED"
            $table->string('category')->nullable(); // e.g., "jobs", "analytics", "support"
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Indexes
            $table->index('key');
            $table->index('app_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feature_definitions');
    }
};
