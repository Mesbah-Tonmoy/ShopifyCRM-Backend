<?php

namespace Tests\Feature\Board;

use App\Http\Resources\Board\FeatureRequestResource;
use App\Models\FeatureRequest;
use App\Services\Board\FeatureRequestService;
use App\Support\Board\BoardIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsBoardFixtures;
use Tests\TestCase;

class ModerationInvariantTest extends TestCase
{
    use BuildsBoardFixtures;
    use RefreshDatabase;

    public function test_a_moderated_board_hides_a_new_submission(): void
    {
        Queue::fake();

        $app = $this->makeApp(['require_approval' => true]);
        $store = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');

        $request = app(FeatureRequestService::class)->submit(
            $app->board,
            new BoardIdentity($app, 'store.myshopify.com', $store->id, $store->store_name, $store->email),
            ['title' => 'Held for review', 'description' => 'Should not be public yet.'],
        );

        $this->assertFalse((bool) $request->is_visible, 'A moderated board must hold a new request back.');
    }

    public function test_an_open_board_publishes_immediately(): void
    {
        Queue::fake();

        $app = $this->makeApp(['require_approval' => false]);
        $store = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');

        $request = app(FeatureRequestService::class)->submit(
            $app->board,
            new BoardIdentity($app, 'store.myshopify.com', $store->id, $store->store_name, $store->email),
            ['title' => 'Straight to the board', 'description' => 'Visible at once.'],
        );

        $this->assertTrue((bool) $request->is_visible);
    }

    /**
     * The submitter keeps sight of its own request while it waits.
     *
     * Hiding it outright would make submitting look like it failed, and invite
     * the same request again.
     */
    public function test_a_held_request_stays_visible_to_the_store_that_sent_it(): void
    {
        Queue::fake();

        $app = $this->makeApp(['require_approval' => true]);
        $store = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');

        app(FeatureRequestService::class)->submit(
            $app->board,
            new BoardIdentity($app, 'store.myshopify.com', $store->id, $store->store_name, $store->email),
            ['title' => 'Held for review', 'description' => 'Should not be public yet.'],
        );

        $mine = FeatureRequest::forApp($app->id)
            ->visibleTo('store.myshopify.com', $app->board)
            ->pluck('title');

        $this->assertContains('Held for review', $mine);
    }

    /**
     * ...and nobody else sees it. This is the half that matters: the scope
     * that keeps a held request on its submitter's board is the same one that
     * must keep it off everyone else's.
     */
    public function test_a_held_request_is_invisible_to_every_other_store(): void
    {
        Queue::fake();

        $app = $this->makeApp(['require_approval' => true]);
        $store = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');
        $this->makeInstallation($app, 'other.myshopify.com', 'other@example.test');

        app(FeatureRequestService::class)->submit(
            $app->board,
            new BoardIdentity($app, 'store.myshopify.com', $store->id, $store->store_name, $store->email),
            ['title' => 'Held for review', 'description' => 'Should not be public yet.'],
        );

        $theirs = FeatureRequest::forApp($app->id)
            ->visibleTo('other.myshopify.com', $app->board)
            ->pluck('title');

        $this->assertNotContains('Held for review', $theirs);

        // An anonymous visitor has no voter key at all, which must not widen
        // what is on show either.
        $anonymous = FeatureRequest::forApp($app->id)
            ->visibleTo(null, $app->board)
            ->pluck('title');

        $this->assertNotContains('Held for review', $anonymous);
    }

    /**
     * The flag the board renders its "only you can see this" notice from.
     */
    public function test_a_held_request_is_marked_as_awaiting_review(): void
    {
        Queue::fake();

        $app = $this->makeApp(['require_approval' => true]);
        $store = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');

        $held = app(FeatureRequestService::class)->submit(
            $app->board,
            new BoardIdentity($app, 'store.myshopify.com', $store->id, $store->store_name, $store->email),
            ['title' => 'Held for review', 'description' => 'Should not be public yet.'],
        );

        $payload = FeatureRequestResource::make($held->fresh())->forBoard($app->board)->resolve();
        $this->assertTrue($payload['is_awaiting_review']);

        // A published request must not carry the notice.
        $published = $this->makeRequest($app, $store, ['title' => 'Already public', 'is_visible' => true]);
        $payload = FeatureRequestResource::make($published)->forBoard($app->board)->resolve();
        $this->assertFalse($payload['is_awaiting_review']);
    }
}
