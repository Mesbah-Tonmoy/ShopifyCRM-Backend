<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\FiltersByLocalDateRange;
use App\Http\Controllers\Controller;
use App\Models\Installation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class InstallationController extends Controller
{
    use FiltersByLocalDateRange;

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
        $this->validateFilters($request);

        $installations = $this->filtered($request)
            ->with('app')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $installations,
        ]);
    }

    /**
     * Download every installation the current filters match, as CSV.
     *
     * Built from the same query as the list rather than from the rows on
     * screen: exporting a page of results is almost never what someone who
     * has just filtered a few thousand stores actually wants.
     *
     * Streamed and read in chunks so the response starts immediately and the
     * row count does not decide how much memory this takes.
     */
    public function export(Request $request)
    {
        $this->validateFilters($request);

        $timezone = $this->filterTimezone($request);
        $query = $this->filtered($request)->with('app');

        $filename = 'installations_export_' . CarbonImmutable::now($timezone)->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($query, $timezone) {
            // A large export can outrun the default limit, and there is no
            // partial CSV worth keeping if it is cut off halfway.
            set_time_limit(0);

            $handle = fopen('php://output', 'wb');

            // Excel reads a bare UTF-8 CSV as the system's legacy code page,
            // which mangles any non-ASCII store name. The BOM is what makes it
            // open the file as UTF-8.
            fwrite($handle, "\xEF\xBB\xBF");

            // escape: '' gives RFC 4180 quoting. PHP's default also treats
            // backslash as an escape character, which writes a field holding
            // one in a way Excel then reads back wrong - and in 8.4 leaving
            // the argument out is deprecated besides.
            $write = fn (array $row) => fputcsv($handle, $row, ',', '"', '');

            $write([
                'App', 'Store', 'Store URL', 'Email', 'Plan',
                'Shopify Plan', 'Status', 'Installs', 'Installed',
            ]);

            // lazy() keeps the caller's sort order, unlike lazyById(); the
            // id tiebreaker added in filtered() is what stops a row being
            // skipped or repeated across chunk boundaries.
            foreach ($query->lazy(500) as $installation) {
                $write([
                    $installation->app?->app_name ?? 'N/A',
                    $installation->store_name,
                    $installation->store_url,
                    $installation->email,
                    $this->planName($installation->app_plan),
                    $installation->shopify_plan ?: 'N/A',
                    $installation->is_active ? 'Installed' : 'Uninstalled',
                    $installation->install_count,
                    $installation->installed_at
                        ? $installation->installed_at->timezone($timezone)->format('M d, Y')
                        : 'N/A',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * The plan name as the list shows it.
     *
     * app_plan is usually {"plan_name": ...}, but older rows hold a bare
     * string, so both shapes are read rather than reporting "No plan" for a
     * store that is plainly on one.
     */
    protected function planName(mixed $appPlan): string
    {
        if (is_array($appPlan)) {
            return (string) ($appPlan['plan_name'] ?? 'No plan');
        }

        return filled($appPlan) ? (string) $appPlan : 'No plan';
    }

    /**
     * Only the date bounds are validated: they are parsed rather than handed
     * straight to SQL, so a malformed one would throw instead of simply
     * matching nothing.
     */
    protected function validateFilters(Request $request): void
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);
    }

    /**
     * The filtered, sorted installation query behind both the list and the
     * export, so the two cannot drift apart.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Installation>
     */
    protected function filtered(Request $request)
    {
        $query = Installation::query();

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
         *
         * The bounds are calendar days in the caller's timezone; see
         * FiltersByLocalDateRange for why that matters.
         */
        $this->whereLocalDateBetween(
            $query,
            $request,
            'COALESCE(installations.installed_at, installations.created_at)',
            $request->input('date_from'),
            $request->input('date_to'),
        );

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

        // Every sortable column has ties, and a tie left to the database can
        // come back in a different order per query - which drops or repeats
        // rows between pages, and between the chunks the export reads.
        return $query->orderBy('installations.id');
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
