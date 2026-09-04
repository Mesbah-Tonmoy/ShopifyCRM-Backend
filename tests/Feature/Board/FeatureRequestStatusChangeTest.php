<?php

namespace Tests\Feature\Board;

use App\Enums\FeatureRequestStatus;
use App\Events\FeatureRequestStatusChanged;
use App\Services\Board\FeatureRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsBoardFixtures;
use Tests\TestCase;

class FeatureRequestStatusChangeTest extends TestCase
{
    use BuildsBoardFixtures;
    use RefreshDatabase;

    protected FeatureRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FeatureRequestService::class);
    }

    public function test_it_moves_the_request_and_logs_the_transition(): void
    {
        Event::fake([FeatureRequestStatusChanged::class]);

        $app = $this->makeApp();
        $request = $this->makeRequest($app);

        $this->service->changeStatus($request, FeatureRequestStatus::InProgress, 'Building it now.');

        $this->assertSame(FeatureRequestStatus::InProgress, $request->fresh()->status);
        $this->assertSame('Building it now.', $request->fresh()->status_note);

        $log = $request->statusLogs()->latest('id')->first();
        $this->assertSame(FeatureRequestStatus::Pending->value, $log->from_status->value);
        $this->assertSame(FeatureRequestStatus::InProgress->value, $log->to_status->value);

        Event::assertDispatched(FeatureRequestStatusChanged::class);
    }

    /**
     * The admin UI sends the move again when a slow first request looks stuck.
     * The second one must not log a transition or mail anyone a second time.
     */
    public function test_it_ignores_a_move_to_the_status_it_already_has(): void
    {
        Event::fake([FeatureRequestStatusChanged::class]);

        $app = $this->makeApp();
        $request = $this->makeRequest($app, null, ['status' => FeatureRequestStatus::Completed]);

        $this->service->changeStatus($request, FeatureRequestStatus::Completed);

        $this->assertSame(0, $request->statusLogs()->count());
        Event::assertNotDispatched(FeatureRequestStatusChanged::class);
    }

    /**
     * A note is worth recording on its own, but it is not a transition, so it
     * must not notify anyone.
     */
    public function test_it_records_a_new_note_without_a_status_change(): void
    {
        Event::fake([FeatureRequestStatusChanged::class]);

        $app = $this->makeApp();
        $request = $this->makeRequest($app, null, ['status' => FeatureRequestStatus::Approved]);

        $this->service->changeStatus($request, FeatureRequestStatus::Approved, 'Scheduled for Q3.');

        $this->assertSame('Scheduled for Q3.', $request->fresh()->status_note);
        $this->assertSame(1, $request->statusLogs()->count());
        Event::assertNotDispatched(FeatureRequestStatusChanged::class);
    }

    public function test_it_publishes_a_request_once_it_leaves_pending(): void
    {
        Event::fake([FeatureRequestStatusChanged::class]);

        $app = $this->makeApp();
        $request = $this->makeRequest($app, null, ['is_visible' => false]);

        $this->service->changeStatus($request, FeatureRequestStatus::Approved);

        $this->assertTrue($request->fresh()->is_visible);
    }

    public function test_it_stamps_completed_at_and_clears_it_when_moved_back(): void
    {
        Event::fake([FeatureRequestStatusChanged::class]);

        $app = $this->makeApp();
        $request = $this->makeRequest($app);

        $this->service->changeStatus($request, FeatureRequestStatus::Completed);
        $this->assertNotNull($request->fresh()->completed_at);

        $this->service->changeStatus($request, FeatureRequestStatus::InProgress);
        $this->assertNull($request->fresh()->completed_at);
    }
}
