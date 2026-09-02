<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores that want to hear about a request without necessarily backing it.
     *
     * Voting already implies interest, so voters are notified too; this covers
     * the store that wants the update but does not want to add its weight to
     * the tally.
     */
    public function up(): void
    {
        Schema::create('feature_request_subscribers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('feature_request_id')->constrained('feature_requests')->onDelete('cascade');
            $table->foreignId('app_id')->constrained('apps')->onDelete('cascade');
            $table->foreignId('installation_id')->nullable()->constrained('installations')->nullOnDelete();

            $table->string('subscriber_key', 191);
            $table->string('email')->nullable();

            $table->timestamps();

            // Explicit names: the generated ones exceed MySQL's 64-character
            // identifier limit for this table name.
            $table->unique(['feature_request_id', 'subscriber_key'], 'fr_subscriber_request_key_unique');
            $table->index(['app_id', 'subscriber_key'], 'fr_subscriber_app_key_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_request_subscribers');
    }
};
