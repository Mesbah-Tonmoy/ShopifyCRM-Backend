<?php

namespace Tests\Feature\Mail;

use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MocksMailProviders;
use Tests\TestCase;

class MailProviderControllerTest extends TestCase
{
    use MocksMailProviders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAws();
    }

    /* -----------------------------------------------------------------
     | Listing
     | -----------------------------------------------------------------
     */

    public function test_it_lists_every_provider_with_its_field_schema(): void
    {
        $response = $this->actingAs($this->userWith())->getJson('/api/integrations/mail-providers');

        $response->assertOk()
            ->assertJsonPath('data.active', null)
            ->assertJsonPath('data.providers.*.key', ['sendgrid', 'mailtrap', 'ses']);

        $ses = collect($response->json('data.providers'))->firstWhere('key', 'ses');
        $this->assertContains('secret_access_key', array_column($ses['fields'], 'key'));
        $this->assertSame('us-east-1', $ses['config']['region'], 'field defaults are filled in');
        $this->assertArrayNotHasKey('rules', $ses['fields'][0], 'validation rules stay server-side');
    }

    public function test_secrets_are_never_returned(): void
    {
        $this->storeProvider('sendgrid', ['api_key' => 'SG.super-secret', 'from_email' => 'a@example.com']);
        $this->storeProvider('ses', $this->sesConfig());

        $response = $this->actingAs($this->userWith())->getJson('/api/integrations/mail-providers');

        $response->assertOk();
        $this->assertStringNotContainsString('SG.super-secret', $response->getContent());
        $this->assertStringNotContainsString('secret-value-never-returned', $response->getContent());

        $sendgrid = collect($response->json('data.providers'))->firstWhere('key', 'sendgrid');
        $this->assertSame(['api_key'], $sendgrid['secrets_set']);
        $this->assertArrayNotHasKey('api_key', $sendgrid['config']);
    }

    public function test_the_generic_integrations_endpoint_no_longer_exposes_mail_providers(): void
    {
        $this->storeProvider('sendgrid', ['api_key' => 'SG.super-secret', 'from_email' => 'a@example.com']);

        $response = $this->actingAs($this->userWith())->getJson('/api/integrations');

        $response->assertOk();
        $this->assertNotContains('sendgrid', array_column($response->json('data'), 'key'));
        $this->assertStringNotContainsString('SG.super-secret', $response->getContent());

        $this->actingAs($this->userWith())
            ->putJson('/api/integrations/sendgrid', ['is_enabled' => true])
            ->assertNotFound();
    }

    /* -----------------------------------------------------------------
     | Saving
     | -----------------------------------------------------------------
     */

    public function test_a_blank_secret_keeps_the_stored_one_and_a_blank_field_clears(): void
    {
        $this->storeProvider('mailtrap', [
            'username' => 'user', 'password' => 'stored-pass', 'from_email' => 'a@example.com', 'reply_to' => 'r@example.com',
        ]);

        $this->actingAs($this->userWith())
            ->putJson('/api/integrations/mail-providers/mailtrap', ['config' => [
                'username' => 'new-user', 'password' => '', 'reply_to' => '', 'port' => '2525', 'not_a_field' => 'x',
            ]])
            ->assertOk();

        $config = Integration::findByKey('mailtrap')->config;
        $this->assertSame('new-user', $config['username']);
        $this->assertSame('stored-pass', $config['password']);
        $this->assertSame(2525, $config['port']);
        $this->assertArrayNotHasKey('reply_to', $config);
        $this->assertArrayNotHasKey('not_a_field', $config);
    }

    public function test_fields_are_validated(): void
    {
        $this->actingAs($this->userWith())
            ->putJson('/api/integrations/mail-providers/ses', ['config' => [
                'region' => 'mars-north-1',
                'from_email' => 'not-an-email',
                'cc' => 'ok@example.com, broken',
                'configuration_set' => 'has spaces',
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['config.region', 'config.from_email', 'config.cc', 'config.configuration_set']);
    }

    public function test_saving_cannot_strip_a_required_field_from_the_active_provider(): void
    {
        $this->storeProvider('sendgrid', ['api_key' => 'SG.x', 'from_email' => 'a@example.com'], active: true);

        $this->actingAs($this->userWith())
            ->putJson('/api/integrations/mail-providers/sendgrid', ['config' => ['from_email' => '']])
            ->assertUnprocessable();

        $this->assertSame('a@example.com', Integration::findByKey('sendgrid')->config['from_email']);
    }

    public function test_saving_preserves_the_ses_tenant_record(): void
    {
        $this->storeProvider('ses', $this->sesConfig(['_tenant' => ['name' => 'shopify-crm-testing']]));

        $this->actingAs($this->userWith())
            ->putJson('/api/integrations/mail-providers/ses', ['config' => ['from_name' => 'Renamed', '_tenant' => null]])
            ->assertOk();

        $this->assertSame(['name' => 'shopify-crm-testing'], Integration::findByKey('ses')->config['_tenant']);
    }

    /* -----------------------------------------------------------------
     | One active at a time
     | -----------------------------------------------------------------
     */

    public function test_activating_a_provider_deactivates_the_others(): void
    {
        $this->storeProvider('sendgrid', ['api_key' => 'SG.x', 'from_email' => 'a@example.com'], active: true);
        $this->storeProvider('ses', $this->sesConfig());

        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/ses/activate')
            ->assertOk()
            ->assertJsonPath('data.active', 'ses');

        $this->assertFalse(Integration::findByKey('sendgrid')->is_enabled);
        $this->assertTrue(Integration::findByKey('ses')->is_enabled);
        $this->assertSame(1, Integration::whereIn('key', ['sendgrid', 'mailtrap', 'ses'])->where('is_enabled', true)->count());
    }

    public function test_ses_over_smtp_can_activate_without_an_access_key(): void
    {
        $this->storeProvider('ses', $this->sesSmtpConfig(['access_key_id' => null, 'secret_access_key' => null]));

        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/ses/activate')
            ->assertOk()
            ->assertJsonPath('data.active', 'ses');
    }

    public function test_ses_required_fields_follow_the_sending_mode(): void
    {
        $this->storeProvider('ses', ['region' => 'us-west-2', 'from_email' => 'a@example.com']);

        $ses = fn () => collect($this->actingAs($this->userWith())->getJson('/api/integrations/mail-providers')->json('data.providers'))->firstWhere('key', 'ses');

        $this->assertSame('smtp', $ses()['config']['send_via'], 'SMTP is the default');
        $this->assertSame(['smtp_username', 'smtp_password'], $ses()['missing']);

        $this->storeProvider('ses', ['send_via' => 'api', 'region' => 'us-west-2', 'from_email' => 'a@example.com']);
        $this->assertSame(['access_key_id', 'secret_access_key'], $ses()['missing']);
    }

    public function test_the_ses_smtp_password_is_never_returned(): void
    {
        $this->storeProvider('ses', $this->sesSmtpConfig());

        $response = $this->actingAs($this->userWith())->getJson('/api/integrations/mail-providers');

        $this->assertStringNotContainsString('smtp-secret-never-returned', $response->getContent());
        $ses = collect($response->json('data.providers'))->firstWhere('key', 'ses');
        $this->assertEqualsCanonicalizing(['smtp_password', 'secret_access_key'], $ses['secrets_set']);
    }

    public function test_an_incomplete_provider_cannot_be_activated(): void
    {
        $this->storeProvider('mailtrap', ['username' => 'user']);

        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/mailtrap/activate')
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'SMTP Password') && str_contains($m, 'From Email'));

        $this->assertFalse(Integration::findByKey('mailtrap')->is_enabled);
    }

    public function test_deactivate_falls_back_to_the_default_mailer(): void
    {
        $this->storeProvider('sendgrid', ['api_key' => 'SG.x', 'from_email' => 'a@example.com'], active: true);

        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/deactivate')
            ->assertOk()
            ->assertJsonPath('data.active', null)
            ->assertJsonPath('data.fallback.mailer', config('mail.default'));

        $this->assertFalse(Integration::findByKey('sendgrid')->is_enabled);
    }

    public function test_unknown_provider_is_not_found(): void
    {
        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/gmail/activate')
            ->assertNotFound();
    }

    /* -----------------------------------------------------------------
     | Permissions
     | -----------------------------------------------------------------
     */

    public function test_view_only_users_cannot_change_anything(): void
    {
        $viewer = $this->userWith(['integrations.view']);
        $this->storeProvider('ses', $this->sesConfig());

        $this->actingAs($viewer)->getJson('/api/integrations/mail-providers')->assertOk();
        $this->actingAs($viewer)->putJson('/api/integrations/mail-providers/ses', ['config' => []])->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/integrations/mail-providers/ses/activate')->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/integrations/mail-providers/deactivate')->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/integrations/mail-providers/ses/test')->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/integrations/mail-providers/ses/tenant')->assertForbidden();
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/integrations/mail-providers')->assertUnauthorized();
    }

    /* -----------------------------------------------------------------
     | Connection test
     | -----------------------------------------------------------------
     */

    public function test_ses_connection_test_checks_account_and_identity_without_sending(): void
    {
        $this->storeProvider('ses', $this->sesConfig());
        $this->aws->append(
            $this->awsResult(['SendingEnabled' => true, 'ProductionAccessEnabled' => false, 'SendQuota' => ['Max24HourSend' => 200]]),
            $this->awsResult(['VerifiedForSendingStatus' => true]),
        );

        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/ses/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.details.production_access', false)
            ->assertJsonPath('data.warnings.0', fn (string $w) => str_contains($w, 'sandbox'));

        $this->assertSame(['GetAccount', 'GetEmailIdentity'], array_column($this->awsCalls, 'name'));
        $this->assertSame('mail.example.com', $this->awsCalls[1]['args']['EmailIdentity']);
    }

    public function test_ses_connection_test_uses_unsaved_edits(): void
    {
        $this->storeProvider('ses', $this->sesConfig());
        $this->aws->append(
            $this->awsResult(['SendingEnabled' => true, 'ProductionAccessEnabled' => true]),
            $this->awsError('NotFoundException', 'GetEmailIdentity'),
        );

        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/ses/test', ['config' => ['identity' => 'other.example.com', 'secret_access_key' => '']])
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.message', fn (string $m) => str_contains($m, 'other.example.com'));

        // Nothing persisted by a test.
        $this->assertArrayNotHasKey('identity', Integration::findByKey('ses')->config);
    }

    public function test_ses_connection_test_tolerates_a_key_that_cannot_read_the_account(): void
    {
        $this->storeProvider('ses', $this->sesConfig());
        $this->aws->append(
            $this->awsError('AccessDeniedException', 'GetAccount', 'not authorized to perform: ses:GetAccount'),
            $this->awsResult(['VerifiedForSendingStatus' => true]),
        );

        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/ses/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.warnings.0', fn (string $w) => str_contains($w, 'ses:GetAccount'));
    }

    public function test_ses_connection_test_reports_rejected_credentials(): void
    {
        $this->storeProvider('ses', $this->sesConfig());
        $this->aws->append($this->awsError('UnrecognizedClientException', 'GetAccount', 'The security token included in the request is invalid.'));

        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/ses/test')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.message', fn (string $m) => str_contains($m, 'security token'));
    }
}
