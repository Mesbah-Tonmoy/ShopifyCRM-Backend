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
        Schema::create('feature_request_votes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('feature_request_id')->constrained('feature_requests')->onDelete('cascade');
            $table->foreignId('app_id')->constrained('apps')->onDelete('cascade');
            $table->foreignId('installation_id')->nullable()->constrained('installations')->nullOnDelete();

            // Normalised shop domain. This is the dedupe key: one vote per store.
            $table->string('voter_key', 191);

            // Reserved for plan-weighted scoring
            $table->unsignedTinyInteger('weight')->default(1);

            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->unique(['feature_request_id', 'voter_key']);
            $table->index(['app_id', 'voter_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feature_request_votes');
    }
};
