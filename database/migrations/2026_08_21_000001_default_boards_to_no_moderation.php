<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Boards publish submissions immediately by default. Requests can still be
     * taken down individually with `is_hidden`, which is the moderation that
     * actually gets used.
     */
    public function up(): void
    {
        Schema::table('feature_boards', function (Blueprint $table) {
            $table->boolean('require_approval')->default(false)->change();
        });

        DB::table('feature_boards')->update(['require_approval' => false]);
    }

    public function down(): void
    {
        Schema::table('feature_boards', function (Blueprint $table) {
            $table->boolean('require_approval')->default(true)->change();
        });
    }
};
