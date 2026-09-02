<?php

namespace App\Http\Controllers\Api\Board;

use App\Http\Resources\Board\FeatureRequestResource;
use App\Models\App;
use App\Models\FeatureRequest;
use App\Models\FeatureRequestSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets a store follow a request without voting for it — useful when a merchant
 * wants to know when something ships but does not want to add weight to it.
 */
class BoardSubscriptionController extends BaseBoardController
{
    public function store(Request $request, App $app, int $featureRequest): JsonResponse
    {
        [$board, $identity, $model] = $this->resolve($request, $app, $featureRequest);

        FeatureRequestSubscriber::firstOrCreate(
            [
                'feature_request_id' => $model->id,
                'subscriber_key' => $identity->voterKey,
            ],
            [
                'app_id' => $model->app_id,
                'installation_id' => $identity->installationId,
                'email' => $identity->email,
            ]
        );

        $model->setAttribute('is_subscribed', true);

        return response()->json([
            'success' => true,
            'message' => "You'll be emailed when this changes.",
            'data' => FeatureRequestResource::make($model)->forBoard($board)->resolve(),
        ]);
    }

    public function destroy(Request $request, App $app, int $featureRequest): JsonResponse
    {
        [$board, $identity, $model] = $this->resolve($request, $app, $featureRequest);

        FeatureRequestSubscriber::where('feature_request_id', $model->id)
            ->where('subscriber_key', $identity->voterKey)
            ->delete();

        $model->setAttribute('is_subscribed', false);

        return response()->json([
            'success' => true,
            'message' => 'You will no longer be emailed about this.',
            'data' => FeatureRequestResource::make($model)->forBoard($board)->resolve(),
        ]);
    }

    /**
     * @return array{0: \App\Models\FeatureBoard, 1: \App\Support\Board\BoardIdentity, 2: FeatureRequest}
     */
    protected function resolve(Request $request, App $app, int $featureRequest): array
    {
        $board = $this->boardFor($app);
        $identity = $this->requireIdentity($request);

        $model = FeatureRequest::forApp($app->id)
            ->visibleTo($identity->voterKey, $board)
            ->withBoardPayload($identity->voterKey)
            ->findOrFail($featureRequest);

        return [$board, $identity, $model];
    }
}
