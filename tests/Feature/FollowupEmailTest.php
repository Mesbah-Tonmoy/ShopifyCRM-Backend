<?php

namespace Tests\Feature;

use App\Mail\TemplateMail;
use App\Models\App;
use App\Models\EmailTemplate;
use App\Models\Installation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FollowupEmailTest extends TestCase
{
    use RefreshDatabase;

    private App $shopifyApp;

    private EmailTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->shopifyApp = App::create([
            'app_name' => 'Test App',
            'app_url' => 'https://test-app.example.com',
        ]);

        $this->template = EmailTemplate::create([
            'app_id' => $this->shopifyApp->id,
            'type' => '7_day_followup',
            'subject' => 'How is {{app_name}} going?',
            'body' => 'Hi {{customer_name}}, {{days_active}} days in. Email: {{email}}. Installed: {{installation_date}}',
            'is_active' => true,
        ]);
    }

    private function installedDaysAgo(float $days, array $overrides = []): Installation
    {
        static $n = 0;
        $n++;

        return Installation::create(array_merge([
            'app_id' => $this->shopifyApp->id,
            'store_name' => "Store {$n}",
            'store_url' => "store-{$n}.myshopify.com",
            'email' => "owner{$n}@example.com",
            'shop_owner_name' => 'Owner',
            'is_active' => true,
            'install_count' => 1,
            'installed_at' => now()->subMinutes((int) round($days * 24 * 60)),
        ], $overrides));
    }

    public function test_sends_to_a_store_on_its_seventh_day_and_marks_it_sent(): void
    {
        $installation = $this->installedDaysAgo(7.1);

        $this->artisan('emails:send-followups')->assertSuccessful();

        Mail::assertSent(TemplateMail::class, function (TemplateMail $mail) use ($installation) {
            return $mail->hasTo($installation->email)
                && $mail->emailSubject === 'How is Test App going?'
                // Whole days, not Carbon 3's fractional diff
                && str_contains($mail->emailBody, '7 days in.')
                // {{email}} used to reach merchants as the literal placeholder
                && str_contains($mail->emailBody, 'Email: ' . $installation->email)
                // The real install date, not when the row reached the CRM
                && str_contains($mail->emailBody, 'Installed: ' . $installation->installed_at->format('M d, Y'));
        });

        $this->assertNotNull($installation->fresh()->followup_email_sent_at);
    }

    public function test_never_sends_the_same_store_twice(): void
    {
        $this->installedDaysAgo(7.1);

        $this->artisan('emails:send-followups')->assertSuccessful();
        $this->artisan('emails:send-followups')->assertSuccessful();

        Mail::assertSent(TemplateMail::class, 1);
    }

    public function test_skips_stores_before_their_seventh_day(): void
    {
        $this->installedDaysAgo(6.9);

        $this->artisan('emails:send-followups')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_catches_up_within_the_grace_window_but_not_older_stores(): void
    {
        $caughtUp = $this->installedDaysAgo(8.5);
        $tooOld = $this->installedDaysAgo(40);

        $this->artisan('emails:send-followups')->assertSuccessful();

        Mail::assertSent(TemplateMail::class, 1);
        Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail) => $mail->hasTo($caughtUp->email));
        $this->assertNull($tooOld->fresh()->followup_email_sent_at);
    }

    public function test_uses_installed_at_rather_than_created_at(): void
    {
        // Imported store: reached the CRM today, installed 7 days ago.
        $this->installedDaysAgo(7.1);
        // Reached the CRM 7 days ago, but installed months before.
        $old = $this->installedDaysAgo(90);
        $old->forceFill(['created_at' => now()->subDays(7)->subHour()])->save();

        $this->artisan('emails:send-followups')->assertSuccessful();

        Mail::assertSent(TemplateMail::class, 1);
    }

    public function test_skips_uninstalled_stores(): void
    {
        $this->installedDaysAgo(7.1, ['is_active' => false]);

        $this->artisan('emails:send-followups')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_sends_nothing_while_the_template_is_inactive(): void
    {
        $this->template->update(['is_active' => false]);
        $installation = $this->installedDaysAgo(7.1);

        $this->artisan('emails:send-followups')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($installation->fresh()->followup_email_sent_at);
    }

    public function test_only_apps_with_an_active_template_are_mailed(): void
    {
        $otherApp = App::create(['app_name' => 'Other', 'app_url' => 'https://other.example.com']);
        EmailTemplate::create([
            'app_id' => $otherApp->id,
            'type' => '7_day_followup',
            'subject' => 'x',
            'body' => 'x',
            'is_active' => false,
        ]);

        $this->installedDaysAgo(7.1);
        $this->installedDaysAgo(7.1, ['app_id' => $otherApp->id]);

        $this->artisan('emails:send-followups')->assertSuccessful();

        Mail::assertSent(TemplateMail::class, 1);
    }

    public function test_dry_run_sends_nothing_and_marks_nothing(): void
    {
        $installation = $this->installedDaysAgo(7.1);

        $this->artisan('emails:send-followups', ['--dry-run' => true])
            ->expectsOutputToContain($installation->email)
            ->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($installation->fresh()->followup_email_sent_at);
    }

    public function test_is_scheduled_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'emails:send-followups'));

        $this->assertNotNull($event, 'emails:send-followups is not on the schedule');
        $this->assertSame('0 * * * *', $event->expression);
    }
}
