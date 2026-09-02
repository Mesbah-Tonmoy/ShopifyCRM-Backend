<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_request_comments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('feature_request_id')->constrained('feature_requests')->onDelete('cascade');
            $table->foreignId('app_id')->constrained('apps')->onDelete('cascade');

            // Merchant author. Denormalised domain so the comment survives an
            // uninstall, mirroring how requests and votes record their author.
            $table->foreignId('installation_id')->nullable()->constrained('installations')->nullOnDelete();
            $table->string('author_shop_domain')->nullable();
            $table->string('author_name')->nullable();

            // Set instead when the comment is an official reply from the team.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_official')->default(false);

            $table->text('body');

            // Admin take-down, matching the flag used on requests.
            $table->boolean('is_hidden')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['feature_request_id', 'is_hidden']);
            $table->index(['app_id', 'author_shop_domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_request_comments');
    }
};
