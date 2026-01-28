<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard stats and chart data
     */
    public function stats()
    {
        // 1. Core Stats
        $totalApps = App::count();
        $totalInstallations = Installation::count();
        $activeStores = Installation::where('is_active', true)->count();
        $totalUsers = User::count();

        // 2. Installation Graph Data (last 30 days) grouped by App
        $days = 30;
        $startDate = Carbon::now()->subDays($days - 1)->startOfDay();
        
        $apps = App::all();
        $chartDatasets = [];
        
        // Get all installations in one query to optimize
        $installations = Installation::select(
                'app_id',
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as count')
            )
            ->where('created_at', '>=', $startDate)
            ->groupBy('app_id', 'date')
            ->get();

        foreach ($apps as $app) {
            $appData = [];
            $appInstalls = $installations->where('app_id', $app->id)->pluck('count', 'date');
            
            for ($i = 0; $i < $days; $i++) {
                $date = $startDate->copy()->addDays($i)->format('Y-m-d');
                $appData[] = [
                    'date' => $startDate->copy()->addDays($i)->format('M d'),
                    'count' => $appInstalls->get($date, 0)
                ];
            }
            
            $chartDatasets[] = [
                'app_name' => $app->app_name,
                'data' => $appData
            ];
        }

        // Also keep a "Total" dataset if needed, or just let the counts exist separately
        // 3. Recent Activity (latest few installations)
        $recentActivity = Installation::with('app')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($installation) {
                return [
                    'id' => $installation->id,
                    'app_name' => $installation->app->app_name,
                    'store_name' => $installation->store_name,
                    'is_active' => $installation->is_active,
                    'created_at' => $installation->created_at->diffForHumans(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => [
                    'total_apps' => $totalApps,
                    'total_installations' => $totalInstallations,
                    'active_stores' => $activeStores,
                    'total_users' => $totalUsers,
                ],
                'chart_data' => $chartDatasets,
                'recent_activity' => $recentActivity,
            ]
        ]);
    }
}
