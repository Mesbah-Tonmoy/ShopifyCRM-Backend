<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PricingPlan;
use App\Models\PlanFeature;
use Illuminate\Http\Request;

class PricingPlanController extends Controller
{
    /**
     * Get all pricing plans
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $query = PricingPlan::with('app');

        if ($request->has('app_id')) {
            $query->where('app_id', $request->get('app_id'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->get('is_active'));
        }

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('display_name', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $plans = $query->orderBy('sort_order', 'asc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }

    /**
     * Update pricing plan
     */
    public function update(Request $request, PricingPlan $pricingPlan)
    {
        $validated = $request->validate([
            'display_name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $pricingPlan->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Pricing plan updated successfully',
            'data' => $pricingPlan,
        ]);
    }

    /**
     * Toggle active status
     */
    public function toggleActive(PricingPlan $pricingPlan)
    {
        $pricingPlan->update([
            'is_active' => !$pricingPlan->is_active
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pricing plan status updated',
            'data' => $pricingPlan,
        ]);
    }

    /**
     * Get features of a pricing plan
     */
    public function features(PricingPlan $pricingPlan)
    {
        $pricingPlan->load('app');
        $features = $pricingPlan->features()->with('feature')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'plan' => $pricingPlan,
                'features' => $features
            ],
        ]);
    }

    /**
     * Update features of a pricing plan
     */
    public function updateFeatures(Request $request, PricingPlan $pricingPlan)
    {
        $validated = $request->validate([
            'features' => 'required|array',
            'features.*.id' => 'required|exists:plan_features,id',
            'features.*.value' => 'required',
        ]);

        foreach ($validated['features'] as $featureData) {
            PlanFeature::where('id', $featureData['id'])
                ->where('plan_id', $pricingPlan->id)
                ->update(['value' => $featureData['value']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Plan features updated successfully',
        ]);
    }
}
