<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two board settings are reworked here.
     *
     * `require_approval` becomes `hide_pending_requests`: the mechanic is
     * unchanged - pending requests stay off the public board until an admin
     * publishes them - but it is now named for what it does rather than for a
     * review workflow that was never really one.
     *
     * `notify_on_approval` goes away entirely. It gated the "email the stores
     * that asked for this" checkbox in the status-change modal, so ticking the
     * box did nothing while the board setting was off. The checkbox is the
     * decision; nothing should sit in front of it.
     */
    public function up(): void
    {
        Schema::table('feature_boards', function (Blueprint $table) {
            $table->renameColumn('require_approval', 'hide_pending_requests');
        });

        Schema::table('feature_boards', function (Blueprint $table) {
            $table->dropColumn('notify_on_approval');
        });
    }

    public function down(): void
    {
        Schema::table('feature_boards', function (Blueprint $table) {
            $table->renameColumn('hide_pending_requests', 'require_approval');
        });

        Schema::table('feature_boards', function (Blueprint $table) {
            $table->boolean('notify_on_approval')->default(false)->after('new_request_email');
        });
    }
};
