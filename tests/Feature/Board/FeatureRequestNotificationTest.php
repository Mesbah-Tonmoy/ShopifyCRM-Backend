<?php

namespace Tests\Feature\Board;

use App\Enums\FeatureRequestStatus;
use App\Events\FeatureRequestStatusChanged;
use App\Jobs\SendFeatureRequestEmail;
use App\Listeners\SendFeatureRequestStatusNotification;
use App\Models\FeatureRequest;
use App\Models\FeatureRequestStatusLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsBoardFixtures;
use Tests\TestCase;

/**
 * The listener is exercised directly rather than through the service, because
 * it is queued: dispatching the event under Queue::fake would capture the
 * listener itself and never reach the recipient logic under test.
 */
class FeatureRequestNotificationTest extends TestCase
{
    use BuildsBoardFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_it_emails_the_store_that_submitted_the_request(): void
    {
        $app = $this->makeApp();
        $submitter = $this->makeInstallation($app, 'submitter.myshopify.com', 'submitter@example.test');
        $request = $this->makeRequest($app, $submitter);

        $this->notify($request, FeatureRequestStatus::Completed);

        Queue::assertPushed(SendFeatureRequestEmail::class, 1);
        Queue::assertPushed(
            SendFeatureRequestEmail::class,
            fn (SendFeatureRequestEmail $job) => $job->installation->is($submitter)
                && $job->templateType === 'feature_request_completed'
        );
    }

    /**
     * Unticking "send an email" in the status modal has to actually suppress
     * the mail, not merely skip the toast.
     */
    public function test_it_sends_nothing_when_notify_is_off_for_the_move(): void
    {
        $app = $this->makeApp();
        $submitter = $this->makeInstallation($app, 'submitter.myshopify.com', 'submitter@example.test');
        $request = $this->makeRequest($app, $submitter);

        $this->notify($request, FeatureRequestStatus::Completed, notify: false);

        Queue::assertNothingPushed();
    }

    public function test_it_sends_nothing_when_the_board_has_notifications_disabled(): void
    {
        $app = $this->makeApp(['notify_on_status_change' => false]);
        $submitter = $this->makeInstallation($app, 'submitter.myshopify.com', 'submitter@example.test');
        $request = $this->makeRequest($app, $submitter);

        $this->notify($request, FeatureRequestStatus::Completed);

        Queue::assertNothingPushed();
    }

    /**
     * A retried job, or a status flipped back and forth, must not mail the same
     * transition twice.
     */
    public function test_it_does_not_send_twice_for_one_transition(): void
    {
        $app = $this->makeApp();
        $submitter = $this->makeInstallation($app, 'submitter.myshopify.com', 'submitter@example.test');
        $request = $this->makeRequest($app, $submitter);

        $log = $this->log($request, FeatureRequestStatus::Completed);

        $this->handle($request, $log);
        $this->handle($request, $log->fresh());

        Queue::assertPushed(SendFeatureRequestEmail::class, 1);
        $this->assertNotNull($log->fresh()->notified_at);
    }

    public function test_it_emails_followers_at_every_stage(): void
    {
        $app = $this->makeApp();
        $follower = $this->makeInstallation($app, 'follower.myshopify.com', 'follower@example.test');
        $request = $this->makeRequest($app);
        $this->addSubscriber($request, $follower);

        // Rejected tells voters nothing, so a follower hearing about it proves
        // subscriptions are not riding on the voter rule.
        $this->notify($request, FeatureRequestStatus::Rejected);

        Queue::assertPushed(SendFeatureRequestEmail::class, 1);
        Queue::assertPushed(
            SendFeatureRequestEmail::class,
            fn (SendFeatureRequestEmail $job) => $job->installation->is($follower)
        );
    }

    public function test_it_emails_voters_only_once_work_starts_or_ships(): void
    {
        $app = $this->makeApp();
        $voter = $this->makeInstallation($app, 'voter.myshopify.com', 'voter@example.test');

        foreach ([FeatureRequestStatus::InProgress, FeatureRequestStatus::Completed] as $status) {
            Queue::fake();

            $request = $this->makeRequest($app);
            $this->addVote($request, $voter);
            $this->notify($request, $status);

            Queue::assertPushed(SendFeatureRequestEmail::class, 1);
        }

        foreach ([FeatureRequestStatus::Approved, FeatureRequestStatus::Rejected] as $status) {
            Queue::fake();

            $request = $this->makeRequest($app);
            $this->addVote($request, $voter);
            $this->notify($request, $status);

            Queue::assertNothingPushed();
        }
    }

    /**
     * A store with no email on file cannot be mailed, and must not stop the
     * others from being.
     */
    public function test_it_skips_stores_with_no_email_on_file(): void
    {
        $app = $this->makeApp();
        $submitter = $this->makeInstallation($app, 'submitter.myshopify.com', 'submitter@example.test');
        $silent = $this->makeInstallation($app, 'silent.myshopify.com');
        $request = $this->makeRequest($app, $submitter);
        $this->addVote($request, $silent);

        $this->notify($request, FeatureRequestStatus::Completed);

        Queue::assertPushed(SendFeatureRequestEmail::class, 1);
    }

    public function test_it_sends_one_email_to_a_store_that_both_submitted_and_voted(): void
    {
        $app = $this->makeApp();
        $store = $this->makeInstallation($app, 'store.myshopify.com', 'store@example.test');
        $request = $this->makeRequest($app, $store);
        $this->addVote($request, $store);

        $this->notify($request, FeatureRequestStatus::Completed);

        Queue::assertPushed(SendFeatureRequestEmail::class, 1);
    }

    /* -----------------------------------------------------------------
     | Helpers
     | -----------------------------------------------------------------
     */

    protected function log(FeatureRequest $request, FeatureRequestStatus $to): FeatureRequestStatusLog
    {
        return FeatureRequestStatusLog::create([
            'feature_request_id' => $request->id,
            'from_status' => FeatureRequestStatus::Pending->value,
            'to_status' => $to->value,
        ]);
    }

    protected function notify(FeatureRequest $request, FeatureRequestStatus $to, bool $notify = true): void
    {
        $this->handle($request, $this->log($request, $to), $notify);
    }

    protected function handle(
        FeatureRequest $request,
        FeatureRequestStatusLog $log,
        bool $notify = true
    ): void {
        app(SendFeatureRequestStatusNotification::class)
            ->handle(new FeatureRequestStatusChanged($request->fresh(), $log, $notify));
    }
}
