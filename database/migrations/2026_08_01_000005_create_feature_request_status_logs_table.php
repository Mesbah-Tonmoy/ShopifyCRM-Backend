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
        Schema::create('feature_request_status_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('feature_request_id')->constrained('feature_requests')->onDelete('cascade');

            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('note')->nullable();

            // Null when the transition was automatic rather than an admin action
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Set once notification emails for this transition have been queued,
            // so a status flip-flop cannot notify voters twice.
            $table->timestamp('notified_at')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('feature_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feature_request_status_logs');
    }
};
