<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The new-request heads-up grows from one address to three lists.
     *
     * `new_request_email` becomes a comma-separated To list, so it widens from
     * a 255-char string to text; cc and bcc join it in the same form. Existing
     * single addresses are already valid one-entry lists, so nothing needs
     * rewriting.
     */
    public function up(): void
    {
        Schema::table('feature_boards', function (Blueprint $table) {
            $table->text('new_request_email')->nullable()->change();
            $table->text('new_request_cc')->nullable()->after('new_request_email');
            $table->text('new_request_bcc')->nullable()->after('new_request_cc');
        });
    }

    public function down(): void
    {
        Schema::table('feature_boards', function (Blueprint $table) {
            $table->dropColumn(['new_request_cc', 'new_request_bcc']);
        });

        // Truncated rather than dropped: a list that outgrew 255 characters
        // cannot come back, and failing the rollback would be worse.
        Schema::table('feature_boards', function (Blueprint $table) {
            $table->string('new_request_email', 255)->nullable()->change();
        });
    }
};
