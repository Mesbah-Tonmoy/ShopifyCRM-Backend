<?php

namespace App\Http\Controllers\Api\Board;

use App\Enums\FeatureRequestStatus;
use App\Http\Resources\Board\FeatureRequestResource;
use App\Models\App;
use App\Models\FeatureRequest;
use App\Services\Board\VoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardController extends BaseBoardController
{
    /**
     * Everything the board needs to render its chrome: branding, which
     * columns to show, and what the merchant is allowed to do.
     */
    public function config(Request $request, App $app): JsonResponse
    {
        $board = $this->boardFor($app);
        $identity = $this->identity($request);

        $counts = FeatureRequest::forApp($app->id)
            ->visibleTo($identity?->voterKey, $board)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'success' => true,
            'data' => [
                'app' => [
                    'name' => $app->app_name,
                    'icon' => $app->icon,
                ],
                'board' => [
                    'slug' => $app->board_slug,
                    'title' => $board->title,
                    'intro' => $board->intro,
                    'theme' => $board->theme,
                    'allow_submissions' => $board->allow_submissions,
                    'allow_voting' => $board->allow_voting,
                    'allow_comments' => $board->allow_comments,
                    'show_vote_counts' => $board->show_vote_counts,
                ],
                'statuses' => array_map(fn ($status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'count' => (int) ($counts[$status->value] ?? 0),
                ], $board->visibleStatuses()),
                'voter' => $identity?->toArray(),
            ],
        ]);
    }

    /**
     * This store's own activity, used to hydrate vote state on load.
     */
    public function me(Request $request, App $app, VoteService $votes): JsonResponse
    {
        $board = $this->boardFor($app);
        $identity = $this->requireIdentity($request);

        $submissions = FeatureRequest::forApp($app->id)
            ->where('submitter_shop_domain', $identity->voterKey)
            ->withVoterState($identity->voterKey)
            ->latest('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'voter' => $identity->toArray(),
                'submissions' => FeatureRequestResource::collectPlain($submissions, $board),
                'stats' => [
                    // Vote state per request already rides on the list endpoint;
                    // this is only the tally for the activity card.
                    'submitted' => $submissions->count(),
                    'voted' => $votes->countVotesBy($identity),
                    'shipped' => $submissions->where('status', FeatureRequestStatus::Completed)->count(),
                ],
            ],
        ]);
    }
}
