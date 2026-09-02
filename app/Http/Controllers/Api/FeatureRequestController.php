<?php

namespace App\Http\Controllers\Api;

use App\Enums\FeatureRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\FeatureRequestResource;
use App\Models\App;
use App\Models\FeatureRequest;
use App\Models\FeatureRequestComment;
use App\Services\Board\CommentService;
use App\Services\Board\FeatureRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeatureRequestController extends Controller
{
    public function __construct(protected FeatureRequestService $requests)
    {
    }

    /**
     * Triage list. Supports both the table view and the kanban board, which
     * asks for one status at a time.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'app_id' => ['nullable', 'integer', 'exists:apps,id'],
            'status' => ['nullable', Rule::in(FeatureRequestStatus::values())],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(['votes', 'trending', 'newest', 'oldest'])],
            'is_visible' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $results = $this->filtered($request, $filters)
            ->with(['app:id,app_name', 'app.board', 'installation:id,store_name,store_url,shopify_plan,app_plan,is_active'])
            ->withCount(['comments', 'subscribers'])
            ->sortedBy($filters['sort'] ?? 'votes')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $results->through(fn (FeatureRequest $model) => FeatureRequestResource::make($model)->resolve()),
        ]);
    }

    /**
     * Counts per status plus the leading requests, for the kanban header and
     * the dashboard widget.
     */
    public function stats(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'app_id' => ['nullable', 'integer', 'exists:apps,id'],
        ]);

        $counts = FeatureRequest::query()
            ->when($filters['app_id'] ?? null, fn ($query, $appId) => $query->forApp($appId))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $top = FeatureRequest::query()
            ->when($filters['app_id'] ?? null, fn ($query, $appId) => $query->forApp($appId))
            ->with(['app:id,app_name', 'app.board'])
            ->sortedBy('votes')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'statuses' => array_map(fn (FeatureRequestStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'count' => (int) ($counts[$status->value] ?? 0),
                ], FeatureRequestStatus::cases()),
                'total' => (int) $counts->sum(),
                'awaiting_review' => (int) ($counts[FeatureRequestStatus::Pending->value] ?? 0),
                'top' => FeatureRequestResource::collection($top)->resolve(),
            ],
        ]);
    }

    /**
     * Full detail, including who voted — the part that tells you which stores
     * are behind a request, not just how many.
     */
    public function show(FeatureRequest $featureRequest): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => FeatureRequestResource::make($this->loadDetail($featureRequest))->resolve(),
        ]);
    }

    /**
     * Everything the detail drawer renders, loaded in one place so the drawer
     * looks the same however it was reached.
     */
    protected function loadDetail(FeatureRequest $featureRequest): FeatureRequest
    {
        return $featureRequest->load([
            'app:id,app_name',
            'app.board',
            'installation',
            'createdBy:id,name',
            'statusLogs.user:id,name',
            'comments' => fn ($query) => $query->with(['installation:id,store_name', 'user:id,name'])->orderBy('created_at'),
            'votes' => fn ($query) => $query->with('installation:id,store_name,shopify_plan,app_plan')->latest(),
            'subscribers' => fn ($query) => $query->with('installation:id,store_name')->latest(),
        ]);
    }

    /**
     * Create a request on behalf of the team, e.g. one that arrived by email.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:apps,id'],
            'title' => ['required', 'string', 'min:5', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(FeatureRequestStatus::values())],
            'admin_note' => ['nullable', 'string', 'max:2000'],
            'submitter_shop_domain' => ['nullable', 'string', 'max:255'],
            'is_visible' => ['nullable', 'boolean'],
        ]);

        $model = $this->requests->createByAdmin($data, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Feature request created successfully',
            'data' => FeatureRequestResource::make($model->load(['app:id,app_name', 'app.board']))->resolve(),
        ], 201);
    }

    /**
     * Edit content and placement. Status moves go through changeStatus so the
     * audit trail is never bypassed.
     */
    public function update(Request $request, FeatureRequest $featureRequest): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:5', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status_note' => ['nullable', 'string', 'max:2000'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
            'is_visible' => ['sometimes', 'boolean'],
            'is_hidden' => ['sometimes', 'boolean'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        $featureRequest->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Feature request updated successfully',
            'data' => FeatureRequestResource::make($featureRequest->fresh(['app:id,app_name', 'app.board']))->resolve(),
        ]);
    }

    /**
     * Move a request to a new status, optionally telling the merchants who
     * backed it.
     */
    public function changeStatus(Request $request, FeatureRequest $featureRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(FeatureRequestStatus::values())],
            'note' => ['nullable', 'string', 'max:2000'],
            'notify' => ['nullable', 'boolean'],
        ]);

        $model = $this->requests->changeStatus(
            $featureRequest,
            FeatureRequestStatus::from($data['status']),
            $data['note'] ?? null,
            $request->user(),
            $data['notify'] ?? true,
        );

        return response()->json([
            'success' => true,
            'message' => 'Status updated successfully',
            'data' => FeatureRequestResource::make($model->load(['app:id,app_name', 'app.board']))->resolve(),
        ]);
    }

    /**
     * Apply one status to many requests, for clearing a triage backlog.
     */
    public function bulkStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'exists:feature_requests,id'],
            'status' => ['required', Rule::in(FeatureRequestStatus::values())],
            'note' => ['nullable', 'string', 'max:2000'],
            'notify' => ['nullable', 'boolean'],
        ]);

        $status = FeatureRequestStatus::from($data['status']);
        $user = $request->user();
        $notify = $data['notify'] ?? true;

        $updated = FeatureRequest::whereIn('id', $data['ids'])
            ->get()
            ->each(fn (FeatureRequest $model) => $this->requests->changeStatus(
                $model,
                $status,
                $data['note'] ?? null,
                $user,
                $notify,
            ))
            ->count();

        return response()->json([
            'success' => true,
            'message' => "{$updated} requests moved to {$status->label()}",
            'data' => ['updated' => $updated],
        ]);
    }

    /**
     * Reply to a request as the team. Badged as official on the public board.
     */
    public function addComment(Request $request, FeatureRequest $featureRequest, CommentService $comments): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        $comments->addOfficialReply($featureRequest, $request->user(), $data['body']);

        return response()->json([
            'success' => true,
            'message' => 'Reply posted',
            'data' => FeatureRequestResource::make($this->loadDetail($featureRequest))->resolve(),
        ], 201);
    }

    /**
     * Hide or restore a merchant comment without destroying the thread.
     */
    public function updateComment(Request $request, FeatureRequestComment $comment): JsonResponse
    {
        $data = $request->validate([
            'is_hidden' => ['required', 'boolean'],
        ]);

        $comment->update($data);

        return response()->json([
            'success' => true,
            'message' => $data['is_hidden'] ? 'Comment hidden' : 'Comment restored',
        ]);
    }

    public function deleteComment(FeatureRequestComment $comment): JsonResponse
    {
        $comment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Comment deleted',
        ]);
    }

    public function destroy(FeatureRequest $featureRequest): JsonResponse
    {
        $featureRequest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Feature request deleted successfully',
        ]);
    }

    /**
     * Requests for one app, mirroring the other per-app endpoints.
     */
    public function byApp(Request $request, App $app): JsonResponse
    {
        return $this->index($request->merge(['app_id' => $app->id]));
    }

    /**
     * Shared filter pipeline for the list and per-app endpoints.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function filtered(Request $request, array $filters)
    {
        return FeatureRequest::query()
            ->when($filters['app_id'] ?? null, fn ($query, $appId) => $query->forApp($appId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->withStatus($status))
            ->when($request->filled('is_visible'), fn ($query) => $query->where('is_visible', $request->boolean('is_visible')))
            ->when($filters['date_from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->search($filters['search'] ?? null);
    }
}
