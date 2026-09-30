<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two notification settings per board.
 *
 * `new_request_email` is a single nullable address rather than an address plus
 * an on/off flag: an address that is set but switched off, or a flag switched
 * on with nowhere to send, are states worth not being able to represent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_boards', function (Blueprint $table) {
            $table->string('new_request_email')->nullable()->after('notify_on_status_change');
            $table->boolean('notify_on_approval')->default(false)->after('new_request_email');
        });
    }

    public function down(): void
    {
        Schema::table('feature_boards', function (Blueprint $table) {
            $table->dropColumn(['new_request_email', 'notify_on_approval']);
        });
    }
};
