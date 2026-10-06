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
 * The per-board notification settings: the heads-up to the team when a
 * request arrives, and the board-wide switch for status emails.
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
            fn (SendBoardTeamEmail $job) => $job->recipient === ['team@example.test']
                && $job->templateType === FeatureBoard::NEW_REQUEST_TEMPLATE
                && $job->appId === $app->id
                && $job->variables['request_title'] === $request->title
                && $job->variables['store_name'] === 'store.myshopify.com'
        );
    }

    public function test_it_emails_every_address_on_the_list_with_cc_and_bcc(): void
    {
        $app = $this->makeApp([
            'new_request_email' => 'team@example.test, product@example.test',
            'new_request_cc' => 'support@example.test',
            'new_request_bcc' => 'archive@example.test, audit@example.test',
        ]);
        $store = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');
        $request = $this->makeRequest($app, $store);

        $this->announceSubmission($request);

        Queue::assertPushed(
            SendBoardTeamEmail::class,
            fn (SendBoardTeamEmail $job) => $job->recipient === ['team@example.test', 'product@example.test']
                && $job->cc === ['support@example.test']
                && $job->bcc === ['archive@example.test', 'audit@example.test']
        );
    }

    /**
     * Whitespace, trailing separators and a repeated address are all things
     * someone pasting a list will produce.
     */
    public function test_it_tidies_up_a_pasted_recipient_list(): void
    {
        $app = $this->makeApp([
            'new_request_email' => '  team@example.test ,, PRODUCT@example.test , team@example.test,',
        ]);
        $request = $this->makeRequest($app, $this->makeInstallation($app, 'store.myshopify.com'));

        $this->assertSame(
            'team@example.test, PRODUCT@example.test',
            $app->board->fresh()->new_request_email,
            'The stored list should be normalised on the way in.'
        );

        $this->announceSubmission($request);

        Queue::assertPushed(
            SendBoardTeamEmail::class,
            fn (SendBoardTeamEmail $job) => $job->recipient === ['team@example.test', 'PRODUCT@example.test']
        );
    }

    /**
     * A cc or bcc describes who else is copied on a message; with no To there
     * is no message, so nothing is sent.
     */
    public function test_a_cc_or_bcc_alone_sends_nothing(): void
    {
        $app = $this->makeApp([
            'new_request_email' => null,
            'new_request_cc' => 'support@example.test',
            'new_request_bcc' => 'archive@example.test',
        ]);
        $request = $this->makeRequest($app, $this->makeInstallation($app, 'store.myshopify.com'));

        $this->announceSubmission($request);

        Queue::assertNotPushed(SendBoardTeamEmail::class);
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
            fn (SendBoardTeamEmail $job) => $job->recipient === ['team@example.test']
                && $job->variables['request_title'] === 'Sticky add-to-cart bar'
        );
    }

    /* -----------------------------------------------------------------
     | Approval reaches the submitter
     | -----------------------------------------------------------------
     */

    /**
     * Approval is an ordinary status change: whoever makes the move decides,
     * with the checkbox in the modal, whether it is worth an email. No board
     * setting sits in front of that choice.
     */
    public function test_it_emails_the_submitter_on_approval(): void
    {
        $app = $this->makeApp();
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
     * Regression: a board that publishes requests on arrival used to swallow
     * the approval email outright, so ticking the box in the modal did
     * nothing. Whether pending requests are held back from the public board
     * says nothing about who gets emailed.
     */
    public function test_it_emails_on_approval_even_when_pending_requests_are_public(): void
    {
        $app = $this->makeApp(['hide_pending_requests' => false]);
        $submitter = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');
        $request = $this->makeRequest($app, $submitter);

        $this->changeStatus($request, FeatureRequestStatus::Approved);

        Queue::assertPushed(SendFeatureRequestEmail::class, 1);
    }

    public function test_it_stays_quiet_on_approval_when_the_admin_unticked_notify(): void
    {
        $app = $this->makeApp();
        $submitter = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');
        $request = $this->makeRequest($app, $submitter);

        $this->changeStatus($request, FeatureRequestStatus::Approved, notify: false);

        Queue::assertNotPushed(SendFeatureRequestEmail::class);
    }

    /**
     * The board-wide switch still turns every status email off at once.
     */
    public function test_it_stays_quiet_when_status_emails_are_off_for_the_board(): void
    {
        $app = $this->makeApp(['notify_on_status_change' => false]);
        $submitter = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');
        $request = $this->makeRequest($app, $submitter);

        $this->changeStatus($request, FeatureRequestStatus::Approved);

        Queue::assertNotPushed(SendFeatureRequestEmail::class);
    }

    public function test_other_statuses_still_reach_the_store_that_asked(): void
    {
        $app = $this->makeApp();
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

    protected function changeStatus(
        FeatureRequest $request,
        FeatureRequestStatus $to,
        bool $notify = true,
    ): void {
        $log = FeatureRequestStatusLog::create([
            'feature_request_id' => $request->id,
            'from_status' => FeatureRequestStatus::Pending->value,
            'to_status' => $to->value,
        ]);

        app(SendFeatureRequestStatusNotification::class)
            ->handle(new FeatureRequestStatusChanged($request->fresh(), $log, $notify));
    }
}
