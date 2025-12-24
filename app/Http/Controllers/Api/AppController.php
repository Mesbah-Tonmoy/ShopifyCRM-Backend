<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Installation;
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
        $apps = App::withCount(['installations', 'activeInstallations'])
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
            // Construct sync URL
            $syncUrl = rtrim($validated['app_url'], '/') . '/api/sync-crm';
            
            // Make GET request to the app's sync endpoint
            $response = Http::timeout(30)->get($syncUrl);
            
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
            $appData = $data['AppData'];
            $app = App::updateOrCreate(
                ['app_url' => $validated['app_url']],
                [
                    'app_name' => $appData['title'],
                    'app_store_url' => $appData['appStoreAppUrl'] ?? null,
                    'icon' => $appData['icon']['url'] ?? null,
                    'last_synced' => now(),
                ]
            );

            // Sync stores/installations
            if (isset($data['stores']) && is_array($data['stores'])) {
                foreach ($data['stores'] as $store) {
                    Installation::updateOrCreate(
                        [
                            'app_id' => $app->id,
                            'store_url' => $store['shopDomain'],
                        ],
                        [
                            'store_name' => $store['name'],
                            'email' => $store['email'],
                            'shop_owner_name' => null,
                            'currency' => $store['currencyCode'],
                            'shopify_plan' => $store['shopifyPlan'],
                            'app_plan' => $store['appPlan'],
                            'plan_started_at' => isset($store['planStartedAt']) ? $store['planStartedAt'] : null,
                            'plan_expires_at' => isset($store['planExpiresAt']) ? $store['planExpiresAt'] : null,
                            'is_active' => $store['isActive'],
                            'install_count' => 1,
                        ]
                    );
                }
            }

            // Seed default email templates for the app
            \Database\Seeders\EmailTemplateSeeder::seedTemplatesForApp($app);

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
     * Update app
     */
    public function update(Request $request, App $app)
    {
        $validated = $request->validate([
            'app_name' => 'sometimes|required|string|max:255',
            'app_url' => 'nullable|url|max:255',
            'app_store_url' => 'nullable|url|max:255',
            'icon' => 'nullable|string|max:255',
            'last_synced' => 'nullable|date',
        ]);

        $app->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'App updated successfully',
            'data' => $app,
        ]);
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
}