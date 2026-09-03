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
        Schema::table('installations', function (Blueprint $table) {
            // Used to keep install/uninstall notifications idempotent when an
            // app re-sends the same webhook (retries, hourly sync jobs, ...).
            $table->timestamp('install_email_sent_at')->nullable()->after('installed_at');
            $table->timestamp('uninstall_email_sent_at')->nullable()->after('install_email_sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('installations', function (Blueprint $table) {
            $table->dropColumn(['install_email_sent_at', 'uninstall_email_sent_at']);
        });
    }
};
