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
        Schema::create('installations', function (Blueprint $table) {
            $table->id();

            // FK to apps.id
            $table->foreignId('app_id')->constrained('apps')->onDelete('cascade');
            
            // Store info
            $table->string('store_name');
            $table->string('store_url');               // e.g., my-shop.myshopify.com (can also store full URL)
            $table->string('email')->nullable();
            $table->string('shop_owner_name')->nullable();

            // Commercial/plan info
            $table->char('currency', 3)->nullable();   // ISO 4217, e.g., USD
            $table->string('shopify_plan')->nullable(); // e.g., basic, plus, etc.
            $table->json('app_plan')->nullable();     // your app’s plan name/tier
            $table->timestamp('plan_started_at')->nullable();
            $table->timestamp('plan_expires_at')->nullable();

            // Status & counters
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('install_count')->default(1);

            $table->timestamps();

            // Helpful indexes
            $table->index(['app_id', 'is_active']);
            $table->index('email');
            $table->index('plan_expires_at');

            // Prevent duplicates for same app & store
            $table->unique(['app_id', 'store_url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installations');
    }
};
