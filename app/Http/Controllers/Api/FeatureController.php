<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Feature;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * "What's new" entries: the changelog each app shows its stores.
 *
 * Separate from the feature request board on purpose — see App\Models\Feature.
 */
class FeatureController extends Controller
{
    /**
     * Columns a caller may order by. An allowlist rather than the raw input:
     * the column name goes into the SQL as an identifier, so anything else is
     * at best a 500 and at worst a probe of the schema.
     */
    protected const SORTABLE = ['release_date', 'title', 'created_at'];

    protected const MAX_PER_PAGE = 50;

    protected const DEFAULT_PER_PAGE = 15;

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request, withApp: true);

        return $this->respondWithPage(
            $this->filtered(Feature::query()->with('app:id,app_name'), $filters)
                ->when(
                    $filters['app_id'] ?? null,
                    fn (Builder $query, int $appId) => $query->forApp($appId)
                ),
            $filters
        );
    }

    public function show(Feature $feature): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $feature->load('app:id,app_name'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $feature = Feature::create($request->validate([
            'app_id' => ['required', 'integer', 'exists:apps,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'release_date' => ['required', 'date'],
            'is_published' => ['boolean'],
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Feature created successfully',
            'data' => $feature->load('app:id,app_name'),
        ], 201);
    }

    public function update(Request $request, Feature $feature): JsonResponse
    {
        $feature->update($request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'release_date' => ['sometimes', 'required', 'date'],
            'is_published' => ['boolean'],
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Feature updated successfully',
            'data' => $feature->load('app:id,app_name'),
        ]);
    }

    public function destroy(Feature $feature): JsonResponse
    {
        $feature->delete();

        return response()->json([
            'success' => true,
            'message' => 'Feature deleted successfully',
        ]);
    }

    /**
     * Every entry for one app, published or not. Admin-facing.
     */
    public function byApp(Request $request, App $app): JsonResponse
    {
        $filters = $this->validateFilters($request);

        return $this->respondWithPage(
            $this->filtered(Feature::query()->forApp($app->id), $filters),
            $filters
        );
    }

    /**
     * Published entries for one app, consumed by the embedded Shopify app to
     * render its "What's new" panel. Open by design; throttled in the route.
     */
    public function publicByApp(Request $request, App $app): JsonResponse
    {
        $filters = $this->validateFilters($request);

        return $this->respondWithPage(
            $this->filtered(Feature::query()->forApp($app->id)->published(), $filters),
            $filters
        );
    }

    public function togglePublished(Feature $feature): JsonResponse
    {
        $feature->update(['is_published' => ! $feature->is_published]);

        return response()->json([
            'success' => true,
            'message' => 'Feature status updated successfully',
            'data' => $feature,
        ]);
    }

    /**
     * Replace the entry's image. SVG is deliberately absent: it is a script
     * carrier, and these are served from the CRM's own origin.
     */
    public function uploadImage(Request $request, Feature $feature): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
        ]);

        $path = $request->file('image')->store('features', 'public');

        // Only after the new one is safely stored.
        if ($feature->image) {
            Storage::disk('public')->delete($feature->image);
        }

        $feature->update(['image' => $path]);

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded successfully',
            'data' => $feature->load('app:id,app_name'),
        ]);
    }

    /* -----------------------------------------------------------------
     | Internals
     | -----------------------------------------------------------------
     */

    /**
     * @return array<string, mixed>
     */
    protected function validateFilters(Request $request, bool $withApp = false): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'is_published' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', Rule::in(self::SORTABLE)],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            // Capped so a single request cannot ask for the whole table.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
            'app_id' => $withApp ? ['nullable', 'integer', 'exists:apps,id'] : ['prohibited'],
        ]);
    }

    /**
     * The one filter and ordering chain, shared by all three listings.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function filtered(Builder $query, array $filters): Builder
    {
        return $query
            ->search($filters['search'] ?? null)
            ->releasedBetween($filters['date_from'] ?? null, $filters['date_to'] ?? null)
            ->when(
                array_key_exists('is_published', $filters) && $filters['is_published'] !== null,
                fn (Builder $q) => $q->where('is_published', (bool) $filters['is_published'])
            )
            ->orderBy($filters['sort_by'] ?? 'release_date', $filters['sort_order'] ?? 'desc');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function respondWithPage(Builder $query, array $filters): JsonResponse
    {
        /** @var LengthAwarePaginator $page */
        $page = $query->paginate($filters['per_page'] ?? self::DEFAULT_PER_PAGE)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $page,
        ]);
    }
}
