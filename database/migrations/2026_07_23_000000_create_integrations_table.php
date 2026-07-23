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
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique(); // e.g. "slack", "gmail", "sendgrid"
            $table->string('name'); // e.g. "Slack"
            $table->boolean('is_enabled')->default(false);
            $table->text('config')->nullable(); // encrypted JSON: webhook_url, api_key, etc.

            $table->timestamps();

            $table->index('is_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
