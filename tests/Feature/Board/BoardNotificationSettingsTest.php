<?php

namespace Tests\Feature\Board;

use App\Enums\FeatureRequestStatus;
use App\Events\FeatureRequestStatusChanged;
use App\Jobs\SendBoardTeamEmail;
use App\Jobs\SendFeatureRequestEmail;
use App\Listeners\NotifyTeamOfNewFeatureRequest;
use App\Listeners\SendFeatureRequestStatusNotification;
use App\Models\FeatureBoard;
use App\Models\FeatureRequest;
use App\Models\FeatureRequestStatusLog;
use App\Services\Board\FeatureRequestService;
use App\Support\Board\BoardIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsBoardFixtures;
use Tests\TestCase;

/**
 * The two per-board notification settings: the heads-up to the team when a
 * request arrives, and the note to the submitter when one is approved.
 *
 * Listeners are exercised directly rather than through the service, for the
 * same reason as FeatureRequestNotificationTest: they are queued, so
 * dispatching the event under Queue::fake would capture the listener itself.
 */
class BoardNotificationSettingsTest extends TestCase
{
    use BuildsBoardFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /* -----------------------------------------------------------------
     | New request reaches the team
     | -----------------------------------------------------------------
     */

    public function test_it_emails_the_board_address_when_a_request_is_submitted(): void
    {
        $app = $this->makeApp(['new_request_email' => 'team@example.test']);
        $store = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');
        $request = $this->makeRequest($app, $store);

        $this->announceSubmission($request);

        Queue::assertPushed(SendBoardTeamEmail::class, 1);
        Queue::assertPushed(
            SendBoardTeamEmail::class,
            fn (SendBoardTeamEmail $job) => $job->recipient === 'team@example.test'
                && $job->templateType === FeatureBoard::NEW_REQUEST_TEMPLATE
                && $job->appId === $app->id
                && $job->variables['request_title'] === $request->title
                && $job->variables['store_name'] === 'store.myshopify.com'
        );
    }

    /**
     * No address is how the heads-up is switched off; there is no second flag
     * that could disagree with it.
     */
    public function test_it_sends_no_heads_up_when_no_address_is_set(): void
    {
        $request = $this->makeRequest($this->makeApp());

        $this->announceSubmission($request);

        Queue::assertNotPushed(SendBoardTeamEmail::class);
    }

    /**
     * A submission is the one transition with nothing before it. Later moves
     * must not re-notify the team.
     */
    public function test_it_sends_no_heads_up_on_a_later_status_change(): void
    {
        $app = $this->makeApp(['new_request_email' => 'team@example.test']);
        $request = $this->makeRequest($app);

        $log = FeatureRequestStatusLog::create([
            'feature_request_id' => $request->id,
            'from_status' => FeatureRequestStatus::Pending->value,
            'to_status' => FeatureRequestStatus::Approved->value,
        ]);

        app(NotifyTeamOfNewFeatureRequest::class)
            ->handle(new FeatureRequestStatusChanged($request, $log, true));

        Queue::assertNotPushed(SendBoardTeamEmail::class);
    }

    /**
     * The listener tests above call the handler directly, which proves the
     * rules but not the wiring. This goes through the real submission so a
     * missing registration in EventServiceProvider cannot pass unnoticed.
     */
    public function test_a_real_submission_reaches_the_team(): void
    {
        $app = $this->makeApp(['new_request_email' => 'team@example.test']);
        $store = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');

        app(FeatureRequestService::class)->submit(
            $app->board,
            new BoardIdentity(
                app: $app,
                voterKey: 'store.myshopify.com',
                installationId: $store->id,
                storeName: $store->store_name,
                email: $store->email,
            ),
            ['title' => 'Sticky add-to-cart bar', 'description' => 'It would help conversions.'],
        );

        Queue::assertPushed(
            SendBoardTeamEmail::class,
            fn (SendBoardTeamEmail $job) => $job->recipient === 'team@example.test'
                && $job->variables['request_title'] === 'Sticky add-to-cart bar'
        );
    }

    /* -----------------------------------------------------------------
     | Approval reaches the submitter
     | -----------------------------------------------------------------
     */

    public function test_it_emails_the_submitter_on_approval_when_review_is_on(): void
    {
        $app = $this->makeApp(['require_approval' => true, 'notify_on_approval' => true]);
        $submitter = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');
        $request = $this->makeRequest($app, $submitter);

        $this->changeStatus($request, FeatureRequestStatus::Approved);

        Queue::assertPushed(
            SendFeatureRequestEmail::class,
            fn (SendFeatureRequestEmail $job) => $job->installation->is($submitter)
                && $job->templateType === 'feature_request_approved'
        );
    }

    /**
     * Without review a request is public the moment it is submitted, so there
     * is no approval worth announcing — even with the setting switched on.
     */
    public function test_it_stays_quiet_on_approval_when_review_is_off(): void
    {
        $app = $this->makeApp(['require_approval' => false, 'notify_on_approval' => true]);
        $submitter = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');
        $request = $this->makeRequest($app, $submitter);

        $this->changeStatus($request, FeatureRequestStatus::Approved);

        Queue::assertNotPushed(SendFeatureRequestEmail::class);
    }

    public function test_it_stays_quiet_on_approval_when_the_setting_is_off(): void
    {
        $app = $this->makeApp(['require_approval' => true, 'notify_on_approval' => false]);
        $submitter = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');
        $request = $this->makeRequest($app, $submitter);

        $this->changeStatus($request, FeatureRequestStatus::Approved);

        Queue::assertNotPushed(SendFeatureRequestEmail::class);
    }

    /**
     * The gate is about approval alone. Shipping something still tells the
     * store that asked for it, whatever the review setting says.
     */
    public function test_the_gate_does_not_touch_other_statuses(): void
    {
        $app = $this->makeApp(['require_approval' => false, 'notify_on_approval' => false]);
        $submitter = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');
        $request = $this->makeRequest($app, $submitter);

        $this->changeStatus($request, FeatureRequestStatus::Completed);

        Queue::assertPushed(SendFeatureRequestEmail::class, 1);
    }

    /* -----------------------------------------------------------------
     | Helpers
     | -----------------------------------------------------------------
     */

    /**
     * A submission: the transition into Pending with nothing before it.
     */
    protected function announceSubmission(FeatureRequest $request): void
    {
        $log = FeatureRequestStatusLog::create([
            'feature_request_id' => $request->id,
            'from_status' => null,
            'to_status' => FeatureRequestStatus::Pending->value,
        ]);

        app(NotifyTeamOfNewFeatureRequest::class)
            ->handle(new FeatureRequestStatusChanged($request->fresh(), $log, true));
    }

    protected function changeStatus(FeatureRequest $request, FeatureRequestStatus $to): void
    {
        $log = FeatureRequestStatusLog::create([
            'feature_request_id' => $request->id,
            'from_status' => FeatureRequestStatus::Pending->value,
            'to_status' => $to->value,
        ]);

        app(SendFeatureRequestStatusNotification::class)
            ->handle(new FeatureRequestStatusChanged($request->fresh(), $log, true));
    }
}
