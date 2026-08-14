<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\App;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FeatureController extends Controller
{
    /**
     * Get all features with filters
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $query = Feature::with('app');

        // Filter by app
        if ($request->has('app_id')) {
            $query->where('app_id', $request->app_id);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('release_date', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('release_date', '<=', $request->date_to);
        }

        // Filter by published status
        if ($request->has('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        // Search by title or description
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'release_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $features = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $features,
        ]);
    }

    /**
     * Get single feature
     */
    public function show(Feature $feature)
    {
        $feature->load('app');

        return response()->json([
            'success' => true,
            'data' => $feature,
        ]);
    }

    /**
     * Create new feature
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'app_id' => 'required|exists:apps,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'release_date' => 'required|date',
            'is_published' => 'boolean',
        ]);

        $feature = Feature::create($validated);
        $feature->load('app');

        return response()->json([
            'success' => true,
            'message' => 'Feature created successfully',
            'data' => $feature,
        ], 201);
    }

    /**
     * Update feature
     */
    public function update(Request $request, Feature $feature)
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'release_date' => 'sometimes|required|date',
            'is_published' => 'boolean',
        ]);

        $feature->update($validated);
        $feature->load('app');

        return response()->json([
            'success' => true,
            'message' => 'Feature updated successfully',
            'data' => $feature,
        ]);
    }

    /**
     * Delete feature
     */
    public function destroy(Feature $feature)
    {
        $feature->delete();

        return response()->json([
            'success' => true,
            'message' => 'Feature deleted successfully',
        ]);
    }

    /**
     * Get features by app
     */
    public function byApp(Request $request, App $app)
    {
        $perPage = $request->get('per_page', 15);
        $query = $app->features();

        if ($request->has('date_from')) {
            $query->whereDate('release_date', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('release_date', '<=', $request->date_to);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where('title', 'like', "%{$search}%");
        }

        $query->orderBy('release_date', 'desc');

        $features = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $features,
        ]);
    }

    /**
     * Upload/replace the image for a feature
     */
    public function uploadImage(Request $request, Feature $feature)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,gif,webp|max:5120',
        ]);

        if ($feature->image) {
            Storage::disk('public')->delete($feature->image);
        }

        $path = $request->file('image')->store('features', 'public');

        $feature->update(['image' => $path]);
        $feature->load('app');

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded successfully',
            'data' => $feature,
        ]);
    }

    /**
     * Toggle feature published status
     */
    public function togglePublished(Feature $feature)
    {
        $feature->update([
            'is_published' => !$feature->is_published,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Feature status updated successfully',
            'data' => $feature,
        ]);
    }

    /**
     * Public: get published features for an app (consumed by the embedded
     * Shopify app to render its "What's new" updates panel).
     */
    public function publicByApp(Request $request, App $app)
    {
        $perPage = $request->get('per_page', 20);

        $features = $app->features()
            ->published()
            ->orderBy('release_date', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $features,
        ]);
    }
}
