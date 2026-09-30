<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\Mail\MailProvider;
use App\Services\Mail\MailProviderRegistry;
use App\Services\Mail\Providers\SesProvider;
use App\Services\Mail\Ses\SesTenantException;
use App\Services\Mail\Ses\SesTenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * The email providers the CRM sends its own mail through, one active at a time.
 *
 * Secrets are write-only: responses list which are set (`secrets_set`) but never
 * their values, and a save that leaves a secret blank keeps the stored one.
 */
class MailProviderController extends Controller
{
    public function __construct(
        private MailProviderRegistry $registry,
        private SesTenantManager $tenants,
    ) {
    }

    public function index(): JsonResponse
    {
        return $this->overview();
    }

    /**
     * Save a provider's settings. Does not change which provider is active.
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $provider = $this->findOrFail($key);
        $input = $this->validatedInput($request, $provider, required: true);
        $integration = $this->registry->integration($provider);
        $merged = $this->merge($provider, $integration->config ?? [], $input);

        // The active provider is what mail is going out through right now; a
        // save must not leave it unable to send.
        if ($integration->is_enabled && $missing = $provider->missingFields($this->registry->withDefaults($provider, $merged))) {
            return $this->fail("{$provider->name()} is active, so its required fields cannot be cleared: " . $this->labels($provider, $missing), 422);
        }

        $integration->update(['config' => $merged]);

        return $this->overview("{$provider->name()} settings saved");
    }

    /**
     * Make this provider the one all CRM mail is sent through.
     */
    public function activate(string $key): JsonResponse
    {
        $provider = $this->findOrFail($key);
        $config = $this->registry->config($provider, $this->registry->integration($provider));

        if ($missing = $provider->missingFields($config)) {
            return $this->fail("Cannot activate {$provider->name()}: fill in " . $this->labels($provider, $missing) . ' and save first.', 422);
        }

        $this->registry->activate($provider);

        Log::info('Mail provider activated', ['provider' => $key, 'by' => request()->user()?->email]);

        return $this->overview("{$provider->name()} is now sending all CRM email");
    }

    /**
     * Stop using every provider; mail falls back to the .env mailer.
     */
    public function deactivate(): JsonResponse
    {
        $this->registry->deactivateAll();

        Log::info('Mail providers deactivated; using default mailer', ['by' => request()->user()?->email]);

        return $this->overview('No provider is active. Email now goes through the default mailer.');
    }

    /**
     * Check a provider's settings — including unsaved edits — without sending mail.
     */
    public function test(Request $request, string $key): JsonResponse
    {
        $provider = $this->findOrFail($key);
        $input = $this->validatedInput($request, $provider, required: false);
        $stored = Integration::findByKey($key)?->config ?? [];
        $config = $this->registry->withDefaults($provider, $this->merge($provider, $stored, $input));

        $result = $provider->testConnection($config);

        return response()->json([
            'success' => true,
            'message' => $result->message,
            'data' => $result->toArray(),
        ]);
    }

    /**
     * This environment's SES tenant, as AWS sees it now.
     */
    public function sesTenant(): JsonResponse
    {
        $provider = $this->findOrFail('ses');
        $integration = $this->registry->integration($provider);
        $config = $this->registry->config($provider, $integration);

        try {
            $live = $this->tenants->liveStatus($config);
        } catch (SesTenantException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        // Keep the stored status current, so the page is right without a round trip.
        $record = $config[SesTenantManager::RECORD_KEY] ?? null;
        if ($record && $live['exists'] && ($record['sending_status'] ?? null) !== $live['sending_status']) {
            $stored = $integration->config ?? [];
            $stored[SesTenantManager::RECORD_KEY]['sending_status'] = $live['sending_status'];
            $integration->update(['config' => $stored]);
            $config = $this->registry->config($provider, $integration);
        }

        return response()->json([
            'success' => true,
            'data' => ['tenant' => $this->tenants->state($config), 'live' => $live],
        ]);
    }

    /**
     * Create this environment's tenant in AWS and associate the identity and configuration set.
     */
    public function provisionSesTenant(Request $request): JsonResponse
    {
        $provider = $this->findOrFail('ses');
        $integration = $this->registry->integration($provider);
        $config = $this->registry->config($provider, $integration);

        try {
            $record = $this->tenants->provision($config);
        } catch (SesTenantException $e) {
            Log::warning('SES tenant provisioning failed', ['tenant' => $this->tenants->tenantName(), 'error' => $e->getMessage()]);

            return $this->fail($e->getMessage(), 422);
        }

        // Re-read so a save made while AWS was answering is not overwritten.
        $integration->refresh();
        $stored = $integration->config ?? [];
        $stored[SesTenantManager::RECORD_KEY] = $record;
        $integration->update(['config' => $stored]);

        Log::info('SES tenant provisioned', Arr::only($record, ['name', 'region', 'identity', 'configuration_set']) + ['by' => $request->user()?->email]);

        return response()->json([
            'success' => true,
            'message' => "Tenant \"{$record['name']}\" is ready in {$record['region']}",
            'data' => ['tenant' => $this->tenants->state($this->registry->config($provider, $integration))],
        ]);
    }

    protected function overview(?string $message = null): JsonResponse
    {
        $activeKey = $this->registry->activeKey();
        $default = (string) config('mail.default');

        $providers = array_values(array_map(function (MailProvider $provider) use ($activeKey) {
            $integration = $this->registry->integration($provider);
            $config = $this->registry->config($provider, $integration);

            return [
                'key' => $provider->key(),
                'name' => $provider->name(),
                'description' => $provider->description(),
                'fields' => array_map(fn (array $f) => Arr::except($f, ['rules']), $provider->fields()),
                'config' => $provider->publicConfig($config),
                'secrets_set' => array_values(array_filter($provider->secretFields(), fn (string $f) => filled($config[$f] ?? null))),
                'missing' => $provider->missingFields($config),
                'is_configured' => $provider->isConfigured($config),
                'is_active' => $provider->key() === $activeKey,
                'meta' => $provider->meta($config),
                'updated_at' => $integration->updated_at,
            ];
        }, $this->registry->all()));

        return response()->json(array_filter([
            'success' => true,
            'message' => $message,
            'data' => [
                'providers' => $providers,
                'active' => $activeKey,
                'fallback' => [
                    'mailer' => $default,
                    'host' => config("mail.mailers.{$default}.host"),
                ],
            ],
        ], fn ($v) => $v !== null));
    }

    /**
     * The submitted settings, limited to this provider's fields and validated.
     *
     * @return array<string, mixed>
     */
    protected function validatedInput(Request $request, MailProvider $provider, bool $required): array
    {
        $request->validate(['config' => ($required ? 'required' : 'nullable') . '|array']);

        $input = Arr::only((array) $request->input('config', []), $provider->fieldKeys());
        $rules = [];

        foreach ($provider->fields() as $field) {
            $fieldRules = ['nullable', $field['type'] === 'number' ? 'integer' : 'string'];

            foreach ($field['rules'] ?? [] as $rule) {
                $fieldRules[] = $rule === 'email_list' ? $this->emailListRule() : $rule;
            }

            $rules["config.{$field['key']}"] = $fieldRules;
        }

        Validator::make(['config' => $input], $rules, [], $this->attributeNames($provider))->validate();

        return $input;
    }

    /**
     * Apply submitted fields over the stored settings.
     *
     * A blank secret keeps the stored one; a blank non-secret clears it. Keys
     * that are not fields (such as the SES tenant record) are left alone.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function merge(MailProvider $provider, array $stored, array $input): array
    {
        $secrets = $provider->secretFields();
        $numbers = array_column(array_filter($provider->fields(), fn (array $f) => $f['type'] === 'number'), 'key');

        foreach ($input as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;

            if (blank($value)) {
                if (! in_array($key, $secrets, true)) {
                    unset($stored[$key]);
                }

                continue;
            }

            $stored[$key] = in_array($key, $numbers, true) ? (int) $value : $value;
        }

        return $stored;
    }

    protected function emailListRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $address) {
                if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $fail("The :attribute contains an invalid address: {$address}");

                    return;
                }
            }
        };
    }

    /**
     * @return array<string, string>
     */
    protected function attributeNames(MailProvider $provider): array
    {
        $names = [];

        foreach ($provider->fields() as $field) {
            $names["config.{$field['key']}"] = $field['label'];
        }

        return $names;
    }

    /**
     * @param  array<int, string>  $keys
     */
    protected function labels(MailProvider $provider, array $keys): string
    {
        $labels = array_column($provider->fields(), 'label', 'key');

        return implode(', ', array_map(fn (string $k) => $labels[$k] ?? $k, $keys));
    }

    protected function findOrFail(string $key): MailProvider
    {
        $provider = $this->registry->find($key);

        abort_unless($provider, 404, 'Unknown mail provider');

        return $provider;
    }

    protected function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
