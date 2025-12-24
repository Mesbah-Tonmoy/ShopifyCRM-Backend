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
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();

            // FK to apps.id
            $table->foreignId('app_id')->constrained('apps')->onDelete('cascade');

            // Template details
            $table->string('type'); // e.g., "welcome", "order_confirmation", "password_reset"
            $table->string('subject');
            $table->text('body'); // Using text type for potentially long email content

            // Status
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Indexes
            $table->index('app_id');
            $table->index(['app_id', 'type']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
