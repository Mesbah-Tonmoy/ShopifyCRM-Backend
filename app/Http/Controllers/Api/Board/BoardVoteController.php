<?php

namespace App\Http\Controllers\Api\Board;

use App\Http\Resources\Board\FeatureRequestResource;
use App\Models\App;
use App\Models\FeatureRequest;
use App\Services\Board\VoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardVoteController extends BaseBoardController
{
    public function __construct(protected VoteService $votes)
    {
    }

    /**
     * Back a request. Voting twice from the same store is a no-op.
     */
    public function store(Request $request, App $app, int $featureRequest): JsonResponse
    {
        [$board, $identity, $model] = $this->resolve($request, $app, $featureRequest);

        $model = $this->votes->add($model, $identity, $request->ip());
        $model->setAttribute('has_voted', true);

        return response()->json([
            'success' => true,
            'message' => 'Vote counted.',
            'data' => FeatureRequestResource::make($model)->forBoard($board)->resolve(),
        ]);
    }

    /**
     * Withdraw a vote.
     */
    public function destroy(Request $request, App $app, int $featureRequest): JsonResponse
    {
        [$board, $identity, $model] = $this->resolve($request, $app, $featureRequest);

        $model = $this->votes->remove($model, $identity);
        $model->setAttribute('has_voted', false);

        return response()->json([
            'success' => true,
            'message' => 'Vote removed.',
            'data' => FeatureRequestResource::make($model)->forBoard($board)->resolve(),
        ]);
    }

    /**
     * Shared guard: the board must accept votes, the caller must be a verified
     * store, and the request must be one that store is allowed to see.
     *
     * @return array{0: \App\Models\FeatureBoard, 1: \App\Support\Board\BoardIdentity, 2: FeatureRequest}
     */
    protected function resolve(Request $request, App $app, int $featureRequest): array
    {
        $board = $this->boardFor($app);

        abort_unless($board->allow_voting, 403, 'Voting is currently closed on this board.');

        $identity = $this->requireIdentity($request);

        $model = FeatureRequest::forApp($app->id)
            ->visibleTo($identity->voterKey, $board)
            ->withBoardPayload($identity->voterKey)
            ->findOrFail($featureRequest);

        return [$board, $identity, $model];
    }
}
