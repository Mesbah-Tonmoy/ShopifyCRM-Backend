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
        Schema::create('feature_boards', function (Blueprint $table) {
            $table->id();

            // One board per app
            $table->foreignId('app_id')->unique()->constrained('apps')->onDelete('cascade');

            // Presentation
            $table->string('title')->nullable();
            $table->text('intro')->nullable();
            $table->json('theme')->nullable();

            // Behaviour
            $table->boolean('is_enabled')->default(true);
            $table->boolean('allow_submissions')->default(true);
            $table->boolean('allow_voting')->default(true);
            $table->boolean('require_approval')->default(true);
            $table->boolean('show_vote_counts')->default(true);
            $table->boolean('notify_on_status_change')->default(true);
            $table->unsignedSmallInteger('submission_limit_per_day')->default(5);

            // Which roadmap columns render publicly, and in what order
            $table->json('visible_statuses')->nullable();

            // Extra origins permitted to embed this board in an iframe
            $table->json('allowed_frame_origins')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feature_boards');
    }
};
