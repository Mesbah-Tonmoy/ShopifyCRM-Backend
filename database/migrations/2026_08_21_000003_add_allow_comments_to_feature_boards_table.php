<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_boards', function (Blueprint $table) {
            $table->boolean('allow_comments')->default(true)->after('allow_voting');
        });
    }

    public function down(): void
    {
        Schema::table('feature_boards', function (Blueprint $table) {
            $table->dropColumn('allow_comments');
        });
    }
};
