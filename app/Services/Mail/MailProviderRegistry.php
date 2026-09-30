<?php

namespace App\Services\Mail;

use App\Models\Integration;
use App\Services\Mail\Providers\MailtrapProvider;
use App\Services\Mail\Providers\SendGridProvider;
use App\Services\Mail\Providers\SesProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The email providers the CRM can send through, and which one it is using.
 *
 * Each provider's settings are an `integrations` row keyed by the provider's
 * key, and `is_enabled` on that row means "active". At most one is active: the
 * CRM sends through that one, or through the .env mailer when none is.
 */
class MailProviderRegistry
{
    /** @var array<string, MailProvider> */
    protected array $providers = [];

    public function __construct(SendGridProvider $sendgrid, MailtrapProvider $mailtrap, SesProvider $ses)
    {
        foreach ([$sendgrid, $mailtrap, $ses] as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    /**
     * @return array<string, MailProvider>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    public function find(string $key): ?MailProvider
    {
        return $this->providers[$key] ?? null;
    }

    public function integration(MailProvider $provider): Integration
    {
        return Integration::firstOrCreate(
            ['key' => $provider->key()],
            ['name' => $provider->name(), 'is_enabled' => false]
        );
    }

    /**
     * Stored settings with field defaults filled in where nothing is stored.
     *
     * @return array<string, mixed>
     */
    public function config(MailProvider $provider, ?Integration $integration = null): array
    {
        return $this->withDefaults($provider, ($integration ?? Integration::findByKey($provider->key()))?->config ?? []);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function withDefaults(MailProvider $provider, array $config): array
    {
        foreach ($provider->fields() as $field) {
            if (array_key_exists('default', $field) && blank($config[$field['key']] ?? null)) {
                $config[$field['key']] = $field['default'];
            }
        }

        return $config;
    }

    public function activeKey(): ?string
    {
        return Integration::whereIn('key', $this->keys())->where('is_enabled', true)->orderBy('id')->value('key');
    }

    /**
     * Make one provider the active one, clearing the flag on every other.
     */
    public function activate(MailProvider $provider): void
    {
        DB::transaction(function () use ($provider) {
            $this->integration($provider);

            // Lock the provider rows so two concurrent activations cannot both win.
            Integration::whereIn('key', $this->keys())->lockForUpdate()->get();

            Integration::whereIn('key', $this->keys())
                ->where('key', '!=', $provider->key())
                ->update(['is_enabled' => false]);

            Integration::where('key', $provider->key())->update(['is_enabled' => true]);
        });
    }

    /**
     * Turn every provider off, so mail goes through the .env mailer.
     */
    public function deactivateAll(): void
    {
        Integration::whereIn('key', $this->keys())->update(['is_enabled' => false]);
    }

    /**
     * Register the active provider's mailer and return it, or null for the .env mailer.
     *
     * An active provider with required settings missing is treated as none, with
     * a warning: mail still goes out, just not where the page says it does.
     */
    public function resolveActive(): ?ResolvedMailProvider
    {
        $key = $this->activeKey();
        $provider = $key ? $this->find($key) : null;

        if (! $provider) {
            return null;
        }

        $config = $this->config($provider);

        if ($missing = $provider->missingFields($config)) {
            Log::warning("{$provider->name()} is the active mail provider but is missing " . implode(', ', $missing) . '; falling back to the default mailer');

            return null;
        }

        $mailer = $provider->mailerName();

        config(["mail.mailers.{$mailer}" => $provider->mailerConfig($config)]);

        // The mail manager caches mailers for the life of the process. A queue
        // worker lives for days, so without this a credential change made on
        // the Integrations page would not reach it until it restarted.
        Mail::purge($mailer);

        return new ResolvedMailProvider($provider, $config, $mailer, $provider->messageHeaders($config), $provider->tenantFor($config));
    }
}
