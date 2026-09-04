<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Housekeeping ahead of the first production deploy.
 *
 * - `allowed_frame_origins` was designed but never wired up: framing is
 *   controlled by the Content-Security-Policy the SPA is served with, not by a
 *   per-board column, and a column nothing reads is a trap for the next person.
 * - The features table carried two indexes that earn nothing. `app_id` alone is
 *   a prefix of `(app_id, release_date)`, and a boolean like `is_published` has
 *   too few distinct values for its own index to be chosen — both only cost
 *   write time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_boards', function (Blueprint $table) {
            if (Schema::hasColumn('feature_boards', 'allowed_frame_origins')) {
                $table->dropColumn('allowed_frame_origins');
            }
        });

        Schema::table('features', function (Blueprint $table) {
            $table->dropIndex('features_app_id_index');
            $table->dropIndex('features_is_published_index');
        });
    }

    public function down(): void
    {
        Schema::table('feature_boards', function (Blueprint $table) {
            $table->json('allowed_frame_origins')->nullable()->after('theme');
        });

        Schema::table('features', function (Blueprint $table) {
            $table->index('app_id');
            $table->index('is_published');
        });
    }
};
