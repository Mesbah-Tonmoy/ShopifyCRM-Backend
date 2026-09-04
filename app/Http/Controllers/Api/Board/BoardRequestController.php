<?php

namespace App\Http\Controllers\Api\Board;

use App\Enums\FeatureRequestStatus;
use App\Http\Resources\Board\FeatureRequestResource;
use App\Models\App;
use App\Models\FeatureBoard;
use App\Models\FeatureRequest;
use App\Services\Board\FeatureRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BoardRequestController extends BaseBoardController
{
    /**
     * Number of cards shown per roadmap column before "show more".
     */
    protected const ROADMAP_PAGE_SIZE = 8;

    public function __construct(protected FeatureRequestService $requests)
    {
    }

    /**
     * Paginated list for the "Feature Requests" tab.
     */
    public function index(Request $request, App $app): JsonResponse
    {
        $board = $this->boardFor($app);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(FeatureRequestStatus::values())],
            'search' => ['nullable', 'string', 'max:180'],
            'match' => ['nullable', Rule::in(['all', 'any'])],
            'sort' => ['nullable', Rule::in(['votes', 'trending', 'newest', 'oldest'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $results = $this->baseQuery($app, $request, $board)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->withStatus($status))
            ->search($filters['search'] ?? null, $filters['match'] ?? 'all')
            ->sortedBy($filters['sort'] ?? 'votes')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'data' => FeatureRequestResource::paginate($results, $board),
        ]);
    }

    /**
     * Every visible column with its leading cards, so the roadmap renders in
     * a single round trip.
     */
    public function roadmap(Request $request, App $app): JsonResponse
    {
        $board = $this->boardFor($app);

        $filters = $request->validate([
            'sort' => ['nullable', Rule::in(['votes', 'trending', 'newest', 'oldest'])],
        ]);

        $sort = $filters['sort'] ?? 'votes';

        $columns = array_map(function (FeatureRequestStatus $status) use ($app, $request, $board, $sort) {
            $query = $this->baseQuery($app, $request, $board)->withStatus($status->value);

            return [
                'status' => $status->value,
                'label' => $status->label(),
                'total' => (clone $query)->toBase()->getCountForPagination(),
                'requests' => FeatureRequestResource::collectPlain(
                    $query->sortedBy($sort)->limit(self::ROADMAP_PAGE_SIZE)->get(),
                    $board
                ),
            ];
        }, $board->visibleStatuses());

        return response()->json([
            'success' => true,
            'data' => ['columns' => $columns],
        ]);
    }

    /**
     * Submit a new request from a verified store.
     */
    public function store(Request $request, App $app): JsonResponse
    {
        $board = $this->boardFor($app);
        $identity = $this->requireIdentity($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:180'],
            // Required from the board: a request nobody can weigh up collects
            // votes on its title alone. The admin form stays optional.
            'description' => ['required', 'string', 'max:2000'],
            // Optional attachment. Merchants explain a request far better with a
            // picture of the screen they are talking about.
            //
            // SVG is excluded deliberately: it is a live document that can
            // carry script, and these files are served from the CRM's own
            // origin. Raster formats cannot execute anything.
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        $model = $this->requests->submit(
            $board,
            $identity,
            $data,
            $request->file('image'),
        );

        // Submitting casts the store's own vote, so hand the card back in the
        // same shape the list would render it.
        $model->loadBoardPayload();
        $model->setAttribute('has_voted', true);

        return response()->json([
            'success' => true,
            'message' => $board->autoPublishesSubmissions()
                ? 'Thanks! Your request is now on the board.'
                : 'Thanks! Your request has been sent for review.',
            'data' => FeatureRequestResource::make($model)->forBoard($board)->resolve(),
        ], 201);
    }

    /**
     * Query shared by every read endpoint, so visibility rules and vote state
     * are applied identically everywhere.
     */
    protected function baseQuery(App $app, Request $request, ?FeatureBoard $board = null)
    {
        $voterKey = $this->voterKey($request);

        return FeatureRequest::forApp($app->id)
            ->visibleTo($voterKey, $board)
            ->withBoardPayload($voterKey);
    }
}
