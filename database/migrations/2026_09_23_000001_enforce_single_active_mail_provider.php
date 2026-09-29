<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Email providers used to be independent toggles, and the sender picked
 * SendGrid over Mailtrap when both were on. They are now one-active-at-a-time,
 * so where more than one is enabled, keep the one that was actually sending
 * and switch the rest off. "Actually sending" matches the old resolver: the
 * first in precedence order that was enabled *and* had its required fields,
 * since an incomplete SendGrid fell through to Mailtrap. Nothing changes for
 * which provider mail goes through.
 *
 * Data only; no schema change. Not reversible in a meaningful way, since the
 * old "enabled but shadowed" state carried no behaviour.
 */
return new class extends Migration
{
    private const PRECEDENCE = ['sendgrid', 'mailtrap'];

    /** Required fields as the old resolver checked them. */
    private const REQUIRED = [
        'sendgrid' => ['api_key', 'from_email'],
        'mailtrap' => ['username', 'password', 'from_email'],
    ];

    public function up(): void
    {
        $enabled = DB::table('integrations')
            ->whereIn('key', self::PRECEDENCE)
            ->where('is_enabled', true)
            ->pluck('config', 'key')
            ->all();

        if (count($enabled) < 2) {
            return;
        }

        $winner = collect(self::PRECEDENCE)->first(
            fn (string $key) => array_key_exists($key, $enabled) && $this->complete($key, $enabled[$key])
        );

        // If none was complete, none was sending; keep the first enabled so the
        // operator still sees which one they meant to use.
        $winner ??= collect(self::PRECEDENCE)->first(fn (string $key) => array_key_exists($key, $enabled));

        DB::table('integrations')
            ->whereIn('key', self::PRECEDENCE)
            ->where('key', '!=', $winner)
            ->update(['is_enabled' => false, 'updated_at' => now()]);
    }

    private function complete(string $key, ?string $encrypted): bool
    {
        try {
            $config = $encrypted ? (json_decode(Crypt::decryptString($encrypted), true) ?: []) : [];
        } catch (\Throwable) {
            return false;
        }

        foreach (self::REQUIRED[$key] as $field) {
            if (empty($config[$field])) {
                return false;
            }
        }

        return true;
    }

    public function down(): void
    {
        //
    }
};
