<?php

namespace Tests\Concerns;

use App\Enums\FeatureRequestStatus;
use App\Models\App;
use App\Models\FeatureBoard;
use App\Models\FeatureRequest;
use App\Models\FeatureRequestSubscriber;
use App\Models\FeatureRequestVote;
use App\Models\Installation;

/**
 * The smallest board that still exercises the real relations: an app with a
 * board, stores installed on it, and requests those stores can act on.
 *
 * Written as plain creates rather than factories because these models carry
 * few required columns, and a test reads better when the row it depends on is
 * visible in the test itself.
 */
trait BuildsBoardFixtures
{
    protected function makeApp(array $board = []): App
    {
        $app = App::create([
            'app_name' => 'Test App',
            'app_url' => 'https://test-app.example',
            'board_slug' => 'test-board',
            'board_public_key' => 'pk_test',
            'board_secret' => 'sk_test',
        ]);

        FeatureBoard::create(array_merge($board, ['app_id' => $app->id]));

        return $app->fresh();
    }

    protected function makeInstallation(App $app, string $domain, ?string $email = null): Installation
    {
        return Installation::create([
            'app_id' => $app->id,
            'store_name' => $domain,
            'store_url' => "https://{$domain}",
            'email' => $email,
        ]);
    }

    protected function makeRequest(
        App $app,
        ?Installation $submitter = null,
        array $attributes = []
    ): FeatureRequest {
        return FeatureRequest::create(array_merge([
            'installation_id' => $submitter?->id,
            'submitter_shop_domain' => $submitter?->store_name,
            'title' => 'Sticky add-to-cart bar',
            'status' => FeatureRequestStatus::Pending,
        ], $attributes, ['app_id' => $app->id]));
    }

    protected function addVote(FeatureRequest $request, Installation $installation): FeatureRequestVote
    {
        return FeatureRequestVote::create([
            'feature_request_id' => $request->id,
            'app_id' => $request->app_id,
            'installation_id' => $installation->id,
            'voter_key' => $installation->store_name,
        ]);
    }

    protected function addSubscriber(
        FeatureRequest $request,
        Installation $installation
    ): FeatureRequestSubscriber {
        return FeatureRequestSubscriber::create([
            'feature_request_id' => $request->id,
            'app_id' => $request->app_id,
            'installation_id' => $installation->id,
            'subscriber_key' => $installation->store_name,
        ]);
    }
}
