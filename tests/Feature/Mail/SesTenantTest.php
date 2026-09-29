<?php

namespace Tests\Feature\Mail;

use App\Models\Integration;
use App\Services\Mail\Ses\SesTenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MocksMailProviders;
use Tests\TestCase;

class SesTenantTest extends TestCase
{
    use MocksMailProviders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAws();
        config(['services.ses_tenant.name' => 'shopify-crm-testing']);
    }

    public function test_the_tenant_name_defaults_to_one_per_environment(): void
    {
        // phpunit.xml blanks SES_TENANT_NAME, so this is the fallback.
        $tenant = (require config_path('services.php'))['ses_tenant'];

        $this->assertSame('shopify-crm-testing', $tenant['name']);
        $this->assertFalse($tenant['explicit']);
    }

    public function test_provisioning_creates_the_tenant_and_associates_identity_and_configuration_set(): void
    {
        $this->storeProvider('ses', $this->sesConfig());
        $this->aws->append(
            $this->awsResult(['Account' => '123456789012', 'Arn' => 'arn:aws:iam::123456789012:user/crm']),
            $this->awsResult(['TenantName' => 'shopify-crm-testing']),
            $this->awsResult(),
            $this->awsResult(),
            $this->awsResult(['Tenant' => ['TenantName' => 'shopify-crm-testing', 'TenantArn' => 'arn:aws:ses:us-west-2:123456789012:tenant/x', 'SendingStatus' => 'ENABLED']]),
        );

        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/ses/tenant')
            ->assertOk()
            ->assertJsonPath('data.tenant.in_use', true)
            ->assertJsonPath('data.tenant.provisioned.sending_status', 'ENABLED');

        $this->assertSame(
            ['GetCallerIdentity', 'CreateTenant', 'CreateTenantResourceAssociation', 'CreateTenantResourceAssociation', 'GetTenant'],
            array_column($this->awsCalls, 'name')
        );
        $this->assertSame('shopify-crm-testing', $this->awsCalls[1]['args']['TenantName']);
        $this->assertSame('arn:aws:ses:us-west-2:123456789012:identity/mail.example.com', $this->awsCalls[2]['args']['ResourceArn']);
        $this->assertSame('arn:aws:ses:us-west-2:123456789012:configuration-set/crm-events', $this->awsCalls[3]['args']['ResourceArn']);

        $record = Integration::findByKey('ses')->config[SesTenantManager::RECORD_KEY];
        $this->assertSame('shopify-crm-testing', $record['name']);
        $this->assertSame('us-west-2', $record['region']);
    }

    public function test_provisioning_is_idempotent(): void
    {
        $this->storeProvider('ses', $this->sesConfig());
        $this->aws->append(
            $this->awsResult(['Account' => '123456789012', 'Arn' => 'arn:aws:iam::123456789012:user/crm']),
            $this->awsError('AlreadyExistsException', 'CreateTenant'),
            $this->awsError('AlreadyExistsException', 'CreateTenantResourceAssociation'),
            $this->awsError('AlreadyExistsException', 'CreateTenantResourceAssociation'),
            $this->awsResult(['Tenant' => ['SendingStatus' => 'ENABLED']]),
        );

        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/ses/tenant')
            ->assertOk()
            ->assertJsonPath('data.tenant.in_use', true);
    }

    public function test_a_key_without_tag_permission_still_provisions_an_untagged_tenant(): void
    {
        $this->storeProvider('ses', $this->sesConfig());
        $this->aws->append(
            $this->awsResult(['Account' => '123456789012', 'Arn' => 'arn:aws:iam::123456789012:user/crm']),
            $this->awsError('AccessDeniedException', 'CreateTenant', 'User: arn:aws:iam::123456789012:user/crm is not authorized to perform: ses:TagResource on resource: arn:aws:ses:us-west-2:123456789012:tenant/shopify-crm-testing/* because no identity-based policy allows the ses:TagResource action'),
            $this->awsResult(['TenantName' => 'shopify-crm-testing']),
            $this->awsResult(),
            $this->awsResult(),
            $this->awsResult(['Tenant' => ['SendingStatus' => 'ENABLED']]),
        );

        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/ses/tenant')
            ->assertOk()
            ->assertJsonPath('data.tenant.in_use', true);

        $creates = array_values(array_filter($this->awsCalls, fn (array $c) => $c['name'] === 'CreateTenant'));
        $this->assertCount(2, $creates);
        $this->assertArrayHasKey('Tags', $creates[0]['args']);
        $this->assertArrayNotHasKey('Tags', $creates[1]['args']);
    }

    public function test_other_access_denials_are_not_retried(): void
    {
        $this->storeProvider('ses', $this->sesConfig());
        $this->aws->append(
            $this->awsResult(['Account' => '123456789012', 'Arn' => 'arn:aws:iam::123456789012:user/crm']),
            $this->awsError('AccessDeniedException', 'CreateTenant', 'User is not authorized to perform: ses:CreateTenant'),
        );

        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/ses/tenant')
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'ses:CreateTenant'));

        $this->assertCount(1, array_filter($this->awsCalls, fn (array $c) => $c['name'] === 'CreateTenant'));
    }

    public function test_live_status_without_list_permission_reports_associations_as_unknown(): void
    {
        $this->storeProvider('ses', $this->sesConfig());
        $this->aws->append(
            $this->awsResult(['Tenant' => ['SendingStatus' => 'ENABLED']]),
            $this->awsError('AccessDeniedException', 'ListTenantResources', 'not authorized to perform: ses:ListTenantResources'),
        );

        $this->actingAs($this->userWith())
            ->getJson('/api/integrations/mail-providers/ses/tenant')
            ->assertOk()
            ->assertJsonPath('data.live.exists', true)
            ->assertJsonPath('data.live.sending_status', 'ENABLED')
            ->assertJsonPath('data.live.identity_associated', null)
            ->assertJsonPath('data.live.associations_unknown_reason', fn (string $r) => str_contains($r, 'ses:ListTenantResources'));
    }

    public function test_an_identity_missing_from_the_region_is_explained(): void
    {
        $this->storeProvider('ses', $this->sesConfig());
        $this->aws->append(
            $this->awsResult(['Account' => '123456789012', 'Arn' => 'arn:aws:iam::123456789012:user/crm']),
            $this->awsResult(),
            $this->awsError('NotFoundException', 'CreateTenantResourceAssociation'),
        );

        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/ses/tenant')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The identity "mail.example.com" is not in SES in us-west-2. Verify it in that region first.');

        $this->assertArrayNotHasKey(SesTenantManager::RECORD_KEY, Integration::findByKey('ses')->config);
    }

    public function test_provisioning_requires_a_configuration_set(): void
    {
        $this->storeProvider('ses', $this->sesConfig(['configuration_set' => null]));

        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/ses/tenant')
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'configuration set'));

        $this->assertSame([], $this->awsCalls);
    }

    public function test_an_invalid_tenant_name_is_refused_before_calling_aws(): void
    {
        config(['services.ses_tenant.name' => 'has.dots.in.it']);
        $this->storeProvider('ses', $this->sesConfig());

        $this->actingAs($this->userWith())
            ->postJson('/api/integrations/mail-providers/ses/tenant')
            ->assertUnprocessable();

        $this->assertSame([], $this->awsCalls);
    }

    public function test_live_status_reports_associations_and_refreshes_the_stored_status(): void
    {
        $this->storeProvider('ses', $this->sesConfig([SesTenantManager::RECORD_KEY => $this->record(['sending_status' => 'ENABLED'])]));
        $this->aws->append(
            $this->awsResult(['Tenant' => ['SendingStatus' => 'DISABLED']]),
            $this->awsResult(['TenantResources' => [
                ['ResourceType' => 'EMAIL_IDENTITY', 'ResourceArn' => 'arn:aws:ses:us-west-2:1:identity/mail.example.com'],
            ]]),
        );

        $this->actingAs($this->userWith(['integrations.view']))
            ->getJson('/api/integrations/mail-providers/ses/tenant')
            ->assertOk()
            ->assertJsonPath('data.live.exists', true)
            ->assertJsonPath('data.live.identity_associated', true)
            ->assertJsonPath('data.live.configuration_set_associated', false)
            ->assertJsonPath('data.tenant.provisioned.sending_status', 'DISABLED');

        $this->assertSame('DISABLED', Integration::findByKey('ses')->config[SesTenantManager::RECORD_KEY]['sending_status']);
    }

    public function test_live_status_when_the_tenant_does_not_exist(): void
    {
        $this->storeProvider('ses', $this->sesConfig());
        $this->aws->append($this->awsError('NotFoundException', 'GetTenant'));

        $this->actingAs($this->userWith())
            ->getJson('/api/integrations/mail-providers/ses/tenant')
            ->assertOk()
            ->assertJsonPath('data.live.exists', false)
            ->assertJsonPath('data.tenant.in_use', false);
    }

    /* -----------------------------------------------------------------
     | Whether mail carries the tenant
     | -----------------------------------------------------------------
     */

    public function test_sends_carry_the_tenant_only_while_the_record_matches(): void
    {
        $tenants = app(SesTenantManager::class);
        $config = $this->sesConfig([SesTenantManager::RECORD_KEY => $this->record()]);

        $this->assertSame('shopify-crm-testing', $tenants->tenantForSend($config));

        $this->assertNull($tenants->tenantForSend(array_merge($config, ['region' => 'us-east-1'])), 'region changed');
        $this->assertNull($tenants->tenantForSend(array_merge($config, ['configuration_set' => 'other'])), 'configuration set changed');
        $this->assertNull($tenants->tenantForSend(array_merge($config, ['from_email' => 'x@other.example.com'])), 'identity changed');
        $this->assertNull($tenants->tenantForSend($this->sesConfig()), 'never provisioned');

        config(['services.ses_tenant.name' => 'shopify-crm-production']);
        $this->assertNull($tenants->tenantForSend($config), 'a record from another environment is not used');
        $this->assertStringContainsString('shopify-crm-production', $tenants->issue($config));
    }

    /**
     * @return array<string, mixed>
     */
    private function record(array $overrides = []): array
    {
        return array_merge([
            'name' => 'shopify-crm-testing',
            'region' => 'us-west-2',
            'identity' => 'mail.example.com',
            'configuration_set' => 'crm-events',
            'sending_status' => 'ENABLED',
            'provisioned_at' => now()->toIso8601String(),
        ], $overrides);
    }
}
