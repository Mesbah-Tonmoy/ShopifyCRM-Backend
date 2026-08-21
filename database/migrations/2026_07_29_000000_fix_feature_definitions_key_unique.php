<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_definitions', function (Blueprint $table) {
            $table->dropUnique('feature_definitions_key_unique');
            $table->unique(['app_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('feature_definitions', function (Blueprint $table) {
            $table->dropUnique(['app_id', 'key']);
            $table->unique('key');
        });
    }
};
