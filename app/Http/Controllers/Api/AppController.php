<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Installation;
use App\Models\PricingPlan;
use App\Models\FeatureDefinition;
use App\Models\PlanFeature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AppController extends Controller
{
    /**
     * Get all apps
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $apps = App::withCount(['installations', 'activeInstallations', 'pricingPlans'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $apps,
        ]);
    }

    /**
     * Get single app with installations
     */
    public function show(App $app)
    {
        $app->load('installations');
        $app->loadCount(['installations', 'activeInstallations']);

        return response()->json([
            'success' => true,
            'data' => $app,
        ]);
    }

    /**
     * Create new app
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'app_url' => 'required|url',
        ]);

        try {
            $secret = config('webhook.secret');
            // Construct sync URL
            $syncUrl = rtrim($validated['app_url'], '/') . '/api/sync-crm';
            
            // Make GET request to the app's sync endpoint
            $response = Http::timeout(30)
                ->withToken($secret)
                ->get($syncUrl);
            
            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to sync with app',
                ], 400);
            }

            $data = $response->json();

            if (!isset($data['success']) || !$data['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'App sync returned unsuccessful response',
                ], 400);
            }

            // Create or update app
            $appData = $data['appData'] ?? $data['AppData'] ?? null;
            if (!$appData) {
                return response()->json([
                    'success' => false,
                    'message' => 'App data not found in response',
                ], 400);
            }

            $app = App::updateOrCreate(
                ['app_url' => $validated['app_url']],
                [
                    'app_name' => $appData['title'] ?? $appData['name'] ?? 'Unknown App',
                    'app_store_url' => $appData['appStoreAppUrl'] ?? null,
                    'icon' => $appData['icon']['url'] ?? null,
                    'last_synced' => now(),
                ]
            );

            // Sync pricing plan and feature definitions if returned
            // Every connected app gets a feature request board straight away, so it
            // can be embedded without a separate setup step. Idempotent: existing
            // slugs and signing credentials are preserved.
            $app->provisionBoard();

            $this->syncPricingData($app, $data);

            $this->syncStores($app, $data);

            // Seed default email templates for the app
            \Database\Seeders\EmailTemplateSeeder::seedTemplatesForApp($app);
            \Database\Seeders\FeatureRequestEmailTemplateSeeder::seedTemplatesForApp($app);

            // Reload app with relationships
            $app->loadCount(['installations', 'activeInstallations']);

            return response()->json([
                'success' => true,
                'message' => 'App connected successfully',
                'data' => $app,
            ], 201);

        } catch (\Exception $e) {
            Log::channel('stderr')->error('App sync error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to connect app: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resync app data
     */
    public function resync(App $app)
    {
        try {
            $secret = config('webhook.secret');
            // Construct sync URL
            $syncUrl = rtrim($app->app_url, '/') . '/api/sync-crm';
            
            // Make GET request to the app's sync endpoint
            $response = Http::timeout(30)
                ->withToken($secret)
                ->get($syncUrl);
            
            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to sync with app',
                ], 400);
            }

            $data = $response->json();

            if (!isset($data['success']) || !$data['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'App sync returned unsuccessful response',
                ], 400);
            }

            // Update app
            $appData = $data['appData'] ?? $data['AppData'] ?? null;
            if (!$appData) {
                return response()->json([
                    'success' => false,
                    'message' => 'App data not found in response',
                ], 400);
            }

            $app->update([
                'app_name' => $appData['title'] ?? $appData['name'] ?? 'Unknown App',
                'app_store_url' => $appData['appStoreAppUrl'] ?? null,
                'icon' => $appData['icon']['url'] ?? null,
                'last_synced' => now(),
            ]);

            // Sync pricing plan and feature definitions if returned
            // Every connected app gets a feature request board straight away, so it
            // can be embedded without a separate setup step. Idempotent: existing
            // slugs and signing credentials are preserved.
            $app->provisionBoard();

            $this->syncPricingData($app, $data);

            $this->syncStores($app, $data);

            // Reload app with relationships
            $app->loadCount(['installations', 'activeInstallations']);

            return response()->json([
                'success' => true,
                'message' => 'App resynced successfully',
                'data' => $app,
            ]);

        } catch (\Exception $e) {
            Log::channel('stderr')->error('App resync error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to resync app: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Write the stores an app reported into installations.
     *
     * A sync is a refresh of an existing record, not a re-creation of it, so
     * it fills gaps and updates what the app is authoritative for, and leaves
     * everything else alone. Writing the whole row every time - which is what
     * this did - meant each sync nulled the owner name the install webhook had
     * recorded, reset install_count to 1 over any reinstall history, and moved
     * installed_at to whatever the app reported at that moment.
     *
     * @param  array<string, mixed>  $data
     */
    protected function syncStores(App $app, array $data): void
    {
        if (! isset($data['stores']) || ! is_array($data['stores'])) {
            return;
        }

        foreach ($data['stores'] as $store) {
            if (blank($store['shopDomain'] ?? null)) {
                continue;
            }

            $installation = Installation::firstOrNew([
                'app_id' => $app->id,
                'store_url' => $store['shopDomain'],
            ]);

            // Present-only: a field the app left out keeps the value we hold
            // rather than being blanked by this sync.
            foreach ([
                'store_name' => 'name',
                'email' => 'email',
                'currency' => 'currencyCode',
                'shopify_plan' => 'shopifyPlan',
                'plan_started_at' => 'planStartedAt',
                'plan_expires_at' => 'planExpiresAt',
            ] as $column => $key) {
                if (filled($store[$key] ?? null)) {
                    $installation->{$column} = $store[$key];
                }
            }

            if (filled($store['appPlan'] ?? null)) {
                $installation->app_plan = ['plan_name' => $store['appPlan']];
            }

            if (array_key_exists('isActive', $store)) {
                $installation->is_active = (bool) $store['isActive'];
            }

            // Filled when missing, never overwritten: the date a store first
            // installed does not change, and an app that reports it wrongly
            // should not be able to rewrite history on every sync.
            if (blank($installation->installed_at) && filled($store['installedAt'] ?? null)) {
                $installation->installed_at = $store['installedAt'];
            }

            // Counted by the install webhook, which is the only thing that can
            // see a reinstall happen.
            if (! $installation->exists) {
                $installation->install_count = 1;
            }

            $installation->save();
        }
    }

    /**
     * Delete app
     */
    public function destroy(App $app)
    {
        $app->delete();

        return response()->json([
            'success' => true,
            'message' => 'App deleted successfully',
        ]);
    }

    /**
     * Push pricing plans to the app
     */
    public function pushPlans(App $app)
    {
        try {
            $secret = config('webhook.secret');
            // Construct update-plans URL
            $updateUrl = rtrim($app->app_url, '/') . '/api/update-plans';
            
            // Get all pricing plans with their features and definitions
            $plans = PricingPlan::where('app_id', $app->id)
                ->with(['features' => function($query) {
                    $query->with('feature');
                }])
                ->get();

            // Format data for the app
            $formattedPlans = $plans->map(function($plan) {
                return [
                    'name' => $plan->name,
                    'amount' => $plan->amount,
                    'isActive' => $plan->is_active,
                    'features' => $plan->features->map(function($pf) {
                        return [
                            'key' => $pf->feature->key,
                            'value' => $pf->value,
                        ];
                    })->all()
                ];
            });

            // Make POST request to the app's update-plans endpoint
            $response = Http::timeout(30)
                ->withToken($secret)
                ->post($updateUrl, $formattedPlans->toArray());
            
            if (!$response->successful()) {
                $errorData = $response->json();
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to push plans: ' . ($errorData['message'] ?? 'App returned an error'),
                ], $response->status());
            }

            return response()->json([
                'success' => true,
                'message' => 'Pricing plans synced to app successfully',
            ]);

        } catch (\Exception $e) {
            Log::channel('stderr')->error('App push plans error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to push plans: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get app statistics
     */
    public function stats(App $app)
    {
        $stats = [
            'total_installations' => $app->installations()->count(),
            'active_installations' => $app->activeInstallations()->count(),
            'inactive_installations' => $app->installations()->where('is_active', false)->count(),
            'installations_with_active_plan' => $app->installations()
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('plan_expires_at')
                        ->orWhere('plan_expires_at', '>', now());
                })
                ->count(),
            'total_reinstalls' => $app->installations()->sum('install_count') - $app->installations()->count(),
            'plans_distribution' => $app->installations()
                ->selectRaw('app_plan, COUNT(*) as count')
                ->whereNotNull('app_plan')
                ->groupBy('app_plan')
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Sync pricing plans and feature definitions from API response
     */
    private function syncPricingData(App $app, array $data): void
    {
        // 1. Sync Feature Definitions
        if (isset($data['featureDefinitions']) && is_array($data['featureDefinitions'])) {
            foreach ($data['featureDefinitions'] as $feature) {
                FeatureDefinition::updateOrCreate(
                    ['app_id' => $app->id, 'key' => $feature['key']],
                    [
                        'name' => $feature['name'],
                        'description' => $feature['description'] ?? null,
                        'value_type' => $feature['valueType'],
                        'category' => $feature['category'] ?? null,
                        'is_active' => $feature['isActive'] ?? true,
                    ]
                );
            }
        }

        // 2. Sync Pricing Plans
        if (isset($data['pricingPlan']) && is_array($data['pricingPlan'])) {
            foreach ($data['pricingPlan'] as $plan) {
                PricingPlan::updateOrCreate(
                    ['app_id' => $app->id, 'name' => $plan['name']],
                    [
                        'display_name' => $plan['displayName'] ?? $plan['name'],
                        'amount' => $plan['amount'],
                        'currency_code' => $plan['currencyCode'] ?? 'USD',
                        'interval' => $plan['interval'],
                        'is_active' => $plan['isActive'] ?? true,
                        'sort_order' => $plan['sortOrder'] ?? 0,
                    ]
                );
            }
        }

        // 3. Sync Plan Features
        if (isset($data['planFeatures']) && (is_array($data['planFeatures']) || is_object($data['planFeatures']))) {
            // Convert to array if single object
            $planFeatures = is_array($data['planFeatures']) ? $data['planFeatures'] : [$data['planFeatures']];
            
            // Build temporary mapping from remote IDs to local Names/Keys 
            // since planFeatures uses remote numeric IDs
            $remotePlanNames = [];
            if (isset($data['pricingPlan']) && is_array($data['pricingPlan'])) {
                foreach ($data['pricingPlan'] as $p) {
                    $remotePlanNames[$p['id']] = $p['name'];
                }
            }

            $remoteFeatureKeys = [];
            if (isset($data['featureDefinitions']) && is_array($data['featureDefinitions'])) {
                foreach ($data['featureDefinitions'] as $f) {
                    $remoteFeatureKeys[$f['id']] = $f['key'];
                }
            }

            foreach ($planFeatures as $pf) {
                // Determine plan name and feature key (handle both camelCase and snake_case for safety)
                $planName = $pf['planName'] ?? $pf['plan_name'] ?? ($remotePlanNames[$pf['planId'] ?? $pf['plan_id'] ?? null] ?? null);
                $featureKey = $pf['featureKey'] ?? $pf['feature_key'] ?? ($remoteFeatureKeys[$pf['featureId'] ?? $pf['feature_id'] ?? null] ?? null);

                if (!$planName || !$featureKey) {
                    continue;
                }

                // Find local IDs based on the names/keys
                $plan = PricingPlan::where('app_id', $app->id)->where('name', $planName)->first();
                $feature = FeatureDefinition::where('app_id', $app->id)->where('key', $featureKey)->first();

                if ($plan && $feature) {
                    PlanFeature::updateOrCreate(
                        [
                            'app_id' => $app->id,
                            'plan_id' => $plan->id,
                            'feature_id' => $feature->id,
                        ],
                        [
                            'value' => $pf['value']
                        ]
                    );
                }
            }
        }
    }
}