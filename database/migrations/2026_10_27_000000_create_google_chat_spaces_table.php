<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Google Chat moves from a single config on the integrations table to one row
 * per space, so each Shopify app (or group of apps) can post to its own space.
 * An existing single config becomes the first space, keeping its settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_chat_spaces', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->text('webhook_url'); // encrypted: the URL carries the space's key and token
            $table->json('events');
            $table->string('app_scope')->default('all'); // "all" or "selected"
            $table->json('app_ids');
            $table->boolean('is_enabled')->default(true);

            $table->timestamps();

            $table->index('is_enabled');
        });

        $this->moveLegacyConfig();
    }

    /**
     * Turn the old single Google Chat config into a space. Separate from
     * up() so it can be tested without schema changes.
     */
    public function moveLegacyConfig(): void
    {
        $legacy = DB::table('integrations')->where('key', 'google_chat')->first();

        if ($legacy) {
            $config = $legacy->config ? json_decode(Crypt::decryptString($legacy->config), true) : [];

            if (!empty($config['webhook_url'])) {
                DB::table('google_chat_spaces')->insert([
                    'name' => 'Google Chat',
                    'webhook_url' => Crypt::encryptString($config['webhook_url']),
                    'events' => json_encode(array_values($config['events'] ?? [])),
                    'app_scope' => $config['app_scope'] ?? 'all',
                    'app_ids' => json_encode(array_values($config['app_ids'] ?? [])),
                    'is_enabled' => (bool) $legacy->is_enabled,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('integrations')->where('key', 'google_chat')->delete();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('google_chat_spaces');
    }
};
