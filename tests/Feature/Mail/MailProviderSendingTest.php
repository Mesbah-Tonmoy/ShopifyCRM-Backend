<?php

namespace Tests\Feature\Mail;

use App\Mail\TemplateMail;
use App\Models\App as ConnectedApp;
use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use App\Services\Mail\MailProviderRegistry;
use App\Services\Mail\Ses\SesTenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\MocksMailProviders;
use Tests\TestCase;

/**
 * Which mailer CRM mail actually goes out through. Mail is faked throughout:
 * nothing here opens a connection or reaches a real provider.
 */
class MailProviderSendingTest extends TestCase
{
    use MocksMailProviders;
    use RefreshDatabase;

    private ConnectedApp $crmApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAws();
        Mail::fake();
        // Stem plus suffix, composing to the name every assertion below uses.
        // Split this way on purpose: the suffix must be pinned, because the
        // real one is derived from APP_KEY and would otherwise differ per
        // machine and make these tests unreproducible.
        config([
            'services.ses_tenant.name' => 'shopify-crm',
            'services.ses_tenant.suffix' => 'testing',
        ]);

        $this->crmApp = ConnectedApp::forceCreate(['app_name' => 'Test App', 'app_url' => 'https://app.example.test']);
        EmailTemplate::forceCreate([
            'app_id' => $this->crmApp->id,
            'type' => 'install',
            'subject' => 'Welcome',
            'body' => '<p>Hello</p>',
            'is_active' => true,
        ]);
    }

    // From/Reply-To are read off the mailable directly: TemplateMail's
    // envelope() sets no from, which makes Mailable::hasFrom() throw.

    public function test_it_sends_through_the_active_ses_provider_under_the_tenant(): void
    {
        $this->storeProvider('ses', $this->sesConfig([
            'reply_to' => 'support@example.com',
            'bcc' => 'audit@example.com, archive@example.com',
            SesTenantManager::RECORD_KEY => [
                'name' => 'shopify-crm-testing',
                'region' => 'us-west-2',
                'identity' => 'mail.example.com',
                'configuration_set' => 'crm-events',
            ],
        ]), active: true);

        $this->assertTrue($this->send());

        Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail) => $mail->mailer === 'ses_dynamic'
            && $mail->from[0]['address'] === 'no-reply@mail.example.com'
            && $mail->from[0]['name'] === 'Shopify CRM'
            && $mail->replyTo[0]['address'] === 'support@example.com'
            && $mail->hasBcc('archive@example.com')
            && $mail->hasTo('store@example.com'));

        $mailer = config('mail.mailers.ses_dynamic');
        $this->assertSame('ses-v2', $mailer['transport']);
        $this->assertSame('us-west-2', $mailer['region']);
        $this->assertSame(['ConfigurationSetName' => 'crm-events', 'TenantName' => 'shopify-crm-testing'], $mailer['options']);
    }

    public function test_ses_over_smtp_uses_the_regional_endpoint(): void
    {
        $this->storeProvider('ses', $this->sesSmtpConfig(), active: true);

        $this->assertTrue($this->send());

        Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail) => $mail->mailer === 'ses_dynamic');

        $mailer = config('mail.mailers.ses_dynamic');
        $this->assertSame('smtp', $mailer['transport']);
        $this->assertSame('email-smtp.us-west-2.amazonaws.com', $mailer['host']);
        $this->assertSame(587, $mailer['port']);
        $this->assertSame('smtp', $mailer['scheme']);
        $this->assertSame('AKIASMTPUSERNAME1234', $mailer['username']);
    }

    public function test_ses_over_smtp_on_2465_uses_implicit_tls(): void
    {
        $this->storeProvider('ses', $this->sesSmtpConfig(['smtp_port' => '2465']), active: true);

        $this->send();

        $this->assertSame('smtps', config('mail.mailers.ses_dynamic.scheme'));
        $this->assertSame(2465, config('mail.mailers.ses_dynamic.port'));
    }

    public function test_ses_over_smtp_puts_the_tenant_and_configuration_set_on_the_message(): void
    {
        // A real mailer, stopped at MessageSending: the message is fully built,
        // headers included, but no connection is ever opened.
        $manager = new MailManager($this->app);
        $this->app->instance('mail.manager', $manager);
        Mail::swap($manager);

        $captured = null;
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$captured) {
            $captured = $event->message;

            return false;
        });

        $this->storeProvider('ses', $this->sesSmtpConfig([
            SesTenantManager::RECORD_KEY => [
                'name' => 'shopify-crm-testing',
                'region' => 'us-west-2',
                'identity' => 'mail.example.com',
                'configuration_set' => 'crm-events',
            ],
        ]), active: true);

        $this->send();

        $this->assertNotNull($captured, 'the message reached the mailer');
        $this->assertSame('shopify-crm-testing', $captured->getHeaders()->get('X-SES-TENANT')?->getBodyAsString());
        $this->assertSame('crm-events', $captured->getHeaders()->get('X-SES-CONFIGURATION-SET')?->getBodyAsString());
    }

    public function test_ses_over_smtp_without_a_tenant_sends_no_tenant_header(): void
    {
        $manager = new MailManager($this->app);
        $this->app->instance('mail.manager', $manager);
        Mail::swap($manager);

        $captured = null;
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$captured) {
            $captured = $event->message;

            return false;
        });

        $this->storeProvider('ses', $this->sesSmtpConfig(), active: true);

        $this->send();

        $this->assertNull($captured->getHeaders()->get('X-SES-TENANT'));
        $this->assertSame('crm-events', $captured->getHeaders()->get('X-SES-CONFIGURATION-SET')?->getBodyAsString());
    }

    public function test_ses_over_the_api_adds_no_headers(): void
    {
        $this->storeProvider('ses', $this->sesConfig(), active: true);

        $this->assertSame([], app(MailProviderRegistry::class)->resolveActive()->headers);
    }

    public function test_ses_without_a_provisioned_tenant_still_sends_untenanted(): void
    {
        $this->storeProvider('ses', $this->sesConfig(), active: true);

        $this->assertTrue($this->send());

        Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail) => $mail->mailer === 'ses_dynamic');
        $this->assertSame(['ConfigurationSetName' => 'crm-events'], config('mail.mailers.ses_dynamic.options'));
    }

    public function test_only_the_active_provider_is_used(): void
    {
        $this->storeProvider('sendgrid', ['api_key' => 'SG.x', 'from_email' => 'sg@example.com']);
        $this->storeProvider('mailtrap', ['username' => 'u', 'password' => 'p', 'from_email' => 'mt@example.com'], active: true);

        $this->assertTrue($this->send());

        Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail) => $mail->mailer === 'mailtrap_dynamic'
            && $mail->from[0]['address'] === 'mt@example.com');
        $this->assertSame('live.smtp.mailtrap.io', config('mail.mailers.mailtrap_dynamic.host'));
    }

    public function test_no_active_provider_uses_the_default_mailer(): void
    {
        $this->storeProvider('sendgrid', ['api_key' => 'SG.x', 'from_email' => 'sg@example.com']);

        $this->assertTrue($this->send());

        Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail) => $mail->mailer === config('mail.default'));
    }

    public function test_an_active_but_incomplete_provider_falls_back_to_the_default_mailer(): void
    {
        $this->storeProvider('mailtrap', ['username' => 'u'], active: true);

        $this->assertTrue($this->send());

        Mail::assertSent(TemplateMail::class, fn (TemplateMail $mail) => $mail->mailer === config('mail.default'));
    }

    public function test_a_credential_change_reaches_a_long_running_process(): void
    {
        // Real mail manager: this is about its mailer cache. Building a
        // transport does not connect, so nothing is sent.
        $manager = new MailManager($this->app);
        $this->app->instance('mail.manager', $manager);
        Mail::swap($manager);
        $registry = app(MailProviderRegistry::class);

        $this->storeProvider('mailtrap', ['username' => 'first', 'password' => 'p', 'from_email' => 'mt@example.com'], active: true);
        $registry->resolveActive();
        $this->assertSame('first', Mail::mailer('mailtrap_dynamic')->getSymfonyTransport()->getUsername());

        $this->storeProvider('mailtrap', ['username' => 'second', 'password' => 'p', 'from_email' => 'mt@example.com'], active: true);
        $registry->resolveActive();
        $this->assertSame('second', Mail::mailer('mailtrap_dynamic')->getSymfonyTransport()->getUsername());
    }

    /* -----------------------------------------------------------------
     | Migration to one-active-at-a-time
     | -----------------------------------------------------------------
     */

    public function test_migration_keeps_the_provider_that_was_actually_sending(): void
    {
        $this->storeProvider('sendgrid', ['api_key' => 'SG.x', 'from_email' => 'a@example.com'], active: true);
        $this->storeProvider('mailtrap', ['username' => 'u', 'password' => 'p', 'from_email' => 'b@example.com'], active: true);

        $this->runSingleActiveMigration();

        $this->assertSame(['sendgrid'], $this->enabledKeys());
    }

    public function test_migration_keeps_mailtrap_when_sendgrid_was_enabled_but_incomplete(): void
    {
        $this->storeProvider('sendgrid', ['from_email' => 'a@example.com'], active: true);
        $this->storeProvider('mailtrap', ['username' => 'u', 'password' => 'p', 'from_email' => 'b@example.com'], active: true);

        $this->runSingleActiveMigration();

        $this->assertSame(['mailtrap'], $this->enabledKeys());
    }

    private function send(): bool
    {
        return app(EmailTemplateService::class)->sendTemplate($this->crmApp->id, 'store@example.com', 'install');
    }

    private function runSingleActiveMigration(): void
    {
        (require database_path('migrations/2026_09_23_000001_enforce_single_active_mail_provider.php'))->up();
    }

    /**
     * @return array<int, string>
     */
    private function enabledKeys(): array
    {
        return DB::table('integrations')->where('is_enabled', true)->orderBy('key')->pluck('key')->all();
    }
}
