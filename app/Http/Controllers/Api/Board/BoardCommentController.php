<?php

namespace App\Http\Controllers\Api\Board;

use App\Http\Resources\Board\CommentResource;
use App\Models\App;
use App\Models\FeatureRequest;
use App\Services\Board\CommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardCommentController extends BaseBoardController
{
    public function __construct(protected CommentService $comments)
    {
    }

    /**
     * Read the discussion on a request. Public, like the rest of the board.
     */
    public function index(Request $request, App $app, int $featureRequest): JsonResponse
    {
        $board = $this->boardFor($app);
        $model = $this->resolveRequest($request, $app, $featureRequest, $board);

        return response()->json([
            'success' => true,
            'data' => CommentResource::collectPlain(
                $this->comments->publicThread($model),
                $board->display_title,
            ),
        ]);
    }

    /**
     * Post a comment as the verified store.
     */
    public function store(Request $request, App $app, int $featureRequest): JsonResponse
    {
        $board = $this->boardFor($app);

        abort_unless($board->allow_comments, 403, 'Comments are turned off on this board.');

        $identity = $this->requireIdentity($request);
        $model = $this->resolveRequest($request, $app, $featureRequest, $board);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        $comment = $this->comments->addFromBoard($model, $identity, $data['body']);

        return response()->json([
            'success' => true,
            'message' => 'Comment posted.',
            'data' => CommentResource::make($comment)->forTeam($board->display_title)->resolve(),
        ], 201);
    }

    /**
     * Only requests this store is allowed to see can be discussed.
     */
    protected function resolveRequest(Request $request, App $app, int $id, $board): FeatureRequest
    {
        return FeatureRequest::forApp($app->id)
            ->visibleTo($this->voterKey($request), $board)
            ->findOrFail($id);
    }
}
