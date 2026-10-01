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
            // Marks the 7-day follow-up as sent, so the hourly run never
            // emails the same store twice.
            $table->timestamp('followup_email_sent_at')->nullable()->after('uninstall_email_sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('installations', function (Blueprint $table) {
            $table->dropColumn('followup_email_sent_at');
        });
    }
};
