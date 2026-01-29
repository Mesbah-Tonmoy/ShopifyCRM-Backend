<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Installation;
use Illuminate\Http\Request;

class InstallationController extends Controller
{
    /**
     * Get all installations with filters
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $query = Installation::with('app');

        // Filter by app
        if ($request->has('app_id')) {
            $query->where('installations.app_id', $request->app_id);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('installations.created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('installations.created_at', '<=', $request->date_to);
        }

        // Filter by plan name
        if ($request->has('plan_name')) {
            $plans = (array) $request->plan_name;
            $query->where(function ($q) use ($plans) {
                foreach ($plans as $plan) {
                    $q->orWhere(function($sub) use ($plan) {
                        $sub->whereRaw("JSON_VALID(app_plan) AND JSON_EXTRACT(app_plan, '$.plan_name') = ?", [$plan])
                            ->orWhere('app_plan', $plan);
                    });
                }
            });
        }

        // Filter by trial status
        if ($request->has('has_trial')) {
            $hasTrial = $request->boolean('has_trial');
            $query->whereRaw("JSON_VALID(app_plan) AND JSON_EXTRACT(app_plan, '$.has_trial') = ?", [$hasTrial]);
        }

        // Filter by plan status
        if ($request->has('plan_status')) {
            $query->whereRaw("JSON_VALID(app_plan) AND JSON_EXTRACT(app_plan, '$.status') = ?", [$request->plan_status]);
        }

        // Filter by shopify plan
        if ($request->has('shopify_plans')) {
            $shopifyPlans = (array) $request->shopify_plans;
            $query->whereIn('shopify_plan', $shopifyPlans);
        }

        // Filter by install count range
        if ($request->has('install_count_min')) {
            $query->where('install_count', '>=', $request->install_count_min);
        }

        if ($request->has('install_count_max')) {
            $query->where('install_count', '<=', $request->install_count_max);
        }

        // Search by store name or email
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('store_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('shop_owner_name', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'installed_at');
        $sortOrder = $request->get('sort_order', 'desc');

        if ($sortBy === 'app_name') {
            $query->join('apps', 'installations.app_id', '=', 'apps.id')
                  ->select('installations.*')
                  ->orderBy('apps.app_name', $sortOrder);
        } elseif ($sortBy === 'app_plan') {
            $query->orderByRaw("JSON_EXTRACT(app_plan, '$.plan_name') " . $sortOrder);
        } else {
            // Qualify other columns to avoid ambiguity with apps table
            $qualifiedSortBy = in_array($sortBy, ['id', 'created_at', 'updated_at']) 
                ? "installations.{$sortBy}" 
                : $sortBy;
            $query->orderBy($qualifiedSortBy, $sortOrder);
        }

        $installations = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $installations,
        ]);
    }

    /**
     * Get single installation
     */
    public function show(Installation $installation)
    {
        $installation->load('app');

        return response()->json([
            'success' => true,
            'data' => $installation,
        ]);
    }

    /**
     * Create new installation
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'app_id' => 'required|exists:apps,id',
            'store_name' => 'required|string|max:255',
            'store_url' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'shop_owner_name' => 'nullable|string|max:255',
            'currency' => 'nullable|string|max:10',
            'shopify_plan' => 'nullable|string|max:255',
            'app_plan' => 'nullable|array',
            'app_plan.plan_name' => 'nullable|string',
            'app_plan.has_trial' => 'nullable|boolean',
            'app_plan.trial_days' => 'nullable|integer|min:0',
            'app_plan.remaining_trial_days' => 'nullable|integer|min:0',
            'app_plan.plan_started_at' => 'nullable|date',
            'app_plan.plan_expires_at' => 'nullable|date',
            'app_plan.status' => 'nullable|string|in:active,trial,expired,cancelled',
            'plan_started_at' => 'nullable|date',
            'plan_expires_at' => 'nullable|date',
            'is_active' => 'boolean',
            'install_count' => 'nullable|integer|min:1',
            'installed_at' => 'nullable|date',
        ]);

        // Handle app_plan JSON structure
        if (isset($validated['app_plan'])) {
            $validated['app_plan'] = json_encode($validated['app_plan']);
        }

        $installation = Installation::create($validated);
        $installation->load('app');

        return response()->json([
            'success' => true,
            'message' => 'Installation created successfully',
            'data' => $installation,
        ], 201);
    }

    /**
     * Update installation
     */
    public function update(Request $request, Installation $installation)
    {
        $validated = $request->validate([
            'store_name' => 'sometimes|required|string|max:255',
            'store_url' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255',
            'shop_owner_name' => 'nullable|string|max:255',
            'currency' => 'nullable|string|max:10',
            'shopify_plan' => 'nullable|string|max:255',
            'app_plan' => 'nullable|array',
            'app_plan.plan_name' => 'nullable|string',
            'app_plan.has_trial' => 'nullable|boolean',
            'app_plan.trial_days' => 'nullable|integer|min:0',
            'app_plan.remaining_trial_days' => 'nullable|integer|min:0',
            'app_plan.plan_started_at' => 'nullable|date',
            'app_plan.plan_expires_at' => 'nullable|date',
            'app_plan.status' => 'nullable|string|in:active,trial,expired,cancelled',
            'plan_started_at' => 'nullable|date',
            'plan_expires_at' => 'nullable|date',
            'is_active' => 'boolean',
            'install_count' => 'nullable|integer|min:1',
            'installed_at' => 'nullable|date',
        ]);

        // Handle app_plan JSON structure
        if (isset($validated['app_plan'])) {
            // Merge with existing app_plan if updating partially
            $currentPlan = $installation->app_plan ?? [];
            $validated['app_plan'] = json_encode(array_merge($currentPlan, $validated['app_plan']));
        }

        $installation->update($validated);
        $installation->load('app');

        return response()->json([
            'success' => true,
            'message' => 'Installation updated successfully',
            'data' => $installation,
        ]);
    }

    /**
     * Delete installation
     */
    public function destroy(Installation $installation)
    {
        $installation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Installation deleted successfully',
        ]);
    }

    /**
     * Get installations by app
     */
    public function byApp(Request $request, App $app)
    {
        $perPage = $request->get('per_page', 15);
        $query = $app->installations();

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Filter by plan status
        if ($request->has('plan_status')) {
            $query->whereRaw("JSON_EXTRACT(app_plan, '$.status') = ?", [$request->plan_status]);
        }

        // Filter by trial status
        if ($request->has('has_trial')) {
            $query->whereRaw("JSON_EXTRACT(app_plan, '$.has_trial') = ?", [$request->boolean('has_trial')]);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('store_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $installations = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $installations,
        ]);
    }
    public function filters()
    {
        $shopifyPlans = Installation::whereNotNull('shopify_plan')
            ->where('shopify_plan', '!=', '')
            ->distinct()
            ->pluck('shopify_plan')
            ->sort()
            ->values();

        $appPlans = Installation::whereNotNull('app_plan')
            ->get()
            ->map(function ($installation) {
                if (is_array($installation->app_plan)) {
                    return $installation->app_plan['plan_name'] ?? null;
                }
                return $installation->app_plan;
            })
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'shopify_plans' => $shopifyPlans,
                'app_plans' => $appPlans,
            ],
        ]);
    }
}