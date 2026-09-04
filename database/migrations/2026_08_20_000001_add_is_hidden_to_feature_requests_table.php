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
        Schema::table('feature_requests', function (Blueprint $table) {
            // Explicit admin override. `is_visible` means "published"; on a
            // board without moderation, pending requests are public anyway, so
            // there was no way to take a single one down. This always wins.
            $table->boolean('is_hidden')->default(false)->after('is_visible');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('feature_requests', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });
    }
};
