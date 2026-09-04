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
        Schema::create('feature_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('app_id')->constrained('apps')->onDelete('cascade');

            // Submitting store. The domain is denormalised so the request
            // survives an uninstall that removes the installation row.
            $table->foreignId('installation_id')->nullable()->constrained('installations')->nullOnDelete();
            $table->string('submitter_shop_domain')->nullable();
            $table->string('submitter_email')->nullable();

            // Content
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('image')->nullable();

            // Workflow
            $table->string('status', 20)->default('pending');
            $table->text('status_note')->nullable();   // public response shown on the card
            $table->text('admin_note')->nullable();    // internal only, never leaves the admin API

            // Board placement
            $table->unsignedInteger('votes_count')->default(0);
            $table->boolean('is_visible')->default(false);
            $table->boolean('is_pinned')->default(false);

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['app_id', 'status']);
            $table->index(['app_id', 'is_visible', 'votes_count']);
            $table->index(['app_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feature_requests');
    }
};
