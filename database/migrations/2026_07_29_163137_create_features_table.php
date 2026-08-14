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
        Schema::create('features', function (Blueprint $table) {
            $table->id();

            // FK to apps.id
            $table->foreignId('app_id')->constrained('apps')->onDelete('cascade');

            // Feature details
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->date('release_date');

            // Status
            $table->boolean('is_published')->default(true);

            $table->timestamps();

            // Indexes
            $table->index('app_id');
            $table->index('release_date');
            $table->index(['app_id', 'release_date']);
            $table->index('is_published');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
