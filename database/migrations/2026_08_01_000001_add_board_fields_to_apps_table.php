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
        Schema::table('apps', function (Blueprint $table) {
            // Public URL segment for the merchant-facing board, e.g. /board/upsell-cart
            $table->string('board_slug')->nullable()->unique()->after('icon');

            // Identifies the app inside a signed board token
            $table->string('board_public_key')->nullable()->unique()->after('board_slug');

            // HMAC signing secret (encrypted at rest, shown once, rotatable)
            $table->text('board_secret')->nullable()->after('board_public_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropUnique(['board_slug']);
            $table->dropUnique(['board_public_key']);
            $table->dropColumn(['board_slug', 'board_public_key', 'board_secret']);
        });
    }
};
