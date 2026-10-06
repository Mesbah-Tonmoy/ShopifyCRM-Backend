<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Installation;
use Illuminate\Http\Request;

class InstallationController extends Controller
{
    /**
     * Columns the installation list can be sorted by.
     */
    private const SORTABLE = [
        'app_name', 'app_plan', 'store_name', 'email', 'shopify_plan',
        'is_active', 'install_count', 'installed_at', 'created_at', 'updated_at', 'id',
    ];

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

        /*
         * Filter by date range.
         *
         * Filters the install date, which is what the list shows and sorts by.
         * created_at is when the CRM first wrote the row, and the two only
         * agree for stores that arrived through the install webhook: every
         * store pulled in by connecting or resyncing an app shares the one
         * created_at of that import, so filtering on it silently matched
         * nothing for those apps.
         *
         * installed_at is nullable - older rows and webhooks that omit it -
         * so those fall back to created_at rather than dropping out of every
         * range.
         */
        $installDate = 'COALESCE(installations.installed_at, installations.created_at)';

        if ($request->filled('date_from')) {
            $query->whereRaw("DATE({$installDate}) >= ?", [$request->date_from]);
        }

        if ($request->filled('date_to')) {
            $query->whereRaw("DATE({$installDate}) <= ?", [$request->date_to]);
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
        if ($request->filled('install_count_min')) {
            $query->where('install_count', '>=', $request->install_count_min);
        }

        if ($request->filled('install_count_max')) {
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

        // Sort. Both values are whitelisted: sort_order is concatenated into
        // raw SQL for the plan column, and an unknown sort_by is a 500.
        $sortBy = in_array($request->get('sort_by'), self::SORTABLE, true)
            ? $request->get('sort_by')
            : 'installed_at';
        $sortOrder = strtolower((string) $request->get('sort_order')) === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'app_name') {
            $query->join('apps', 'installations.app_id', '=', 'apps.id')
                  ->select('installations.*')
                  ->orderBy('apps.app_name', $sortOrder);
        } elseif ($sortBy === 'app_plan') {
            $query->orderByRaw("JSON_EXTRACT(app_plan, '$.plan_name') " . $sortOrder);
        } else {
            // Qualified to avoid ambiguity with the apps table
            $query->orderBy("installations.{$sortBy}", $sortOrder);
        }

        $installations = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $installations,
        ]);
    }

    /**
     * Distinct plan values for the advanced filter, limited to one app's
     * stores when app_id is given so an app's list offers only its own plans.
     */
    public function filters(Request $request)
    {
        $scoped = fn () => Installation::query()
            ->when($request->filled('app_id'), fn ($q) => $q->where('app_id', $request->app_id));

        $shopifyPlans = $scoped()
            ->whereNotNull('shopify_plan')
            ->where('shopify_plan', '!=', '')
            ->distinct()
            ->pluck('shopify_plan')
            ->sort()
            ->values();

        // app_plan is JSON - either {"plan_name": ...} or, for older rows, a
        // bare string - so the name is pulled out after the cast.
        $appPlans = $scoped()
            ->whereNotNull('app_plan')
            ->pluck('app_plan')
            ->map(fn ($plan) => is_array($plan) ? ($plan['plan_name'] ?? null) : $plan)
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
