<?php

namespace Tests\Feature;

use App\Mail\TemplateMail;
use App\Models\App;
use App\Models\EmailTemplate;
use App\Models\Installation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private App $shopifyApp;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);

        Mail::fake();

        $this->shopifyApp = App::create([
            'app_name' => 'Test App',
            'app_url' => 'https://test-app.example.com',
        ]);

        foreach (['install', 'uninstall'] as $type) {
            EmailTemplate::create([
                'app_id' => $this->shopifyApp->id,
                'type' => $type,
                'subject' => ucfirst($type) . ' - {{store_name}}',
                'body' => 'Hello {{customer_name}}',
                'is_active' => true,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function installPayload(array $overrides = []): array
    {
        return array_merge([
            'app_url' => $this->shopifyApp->app_url,
            'shop_domain' => 'test-store.myshopify.com',
            'name' => 'Test Store',
            'email' => 'owner@test-store.example.com',
            'currency_code' => 'USD',
            'shopify_plan' => 'Basic',
        ], $overrides);
    }

    public function test_new_install_records_the_installation_and_sends_one_email(): void
    {
        $response = $this->postJson('/api/webhooks/install', $this->installPayload());

        $response->assertOk()->assertJson(['success' => true]);

        $installation = Installation::firstWhere('store_url', 'test-store.myshopify.com');

        $this->assertNotNull($installation);
        $this->assertSame(1, $installation->install_count);
        $this->assertNotNull($installation->install_email_sent_at);

        Mail::assertSent(TemplateMail::class, 1);
    }

    public function test_repeated_install_webhooks_do_not_resend_the_email(): void
    {
        // What the live app does: the same install webhook arrives again and
        // again (retry job / periodic sync) for a store we already have.
        $this->postJson('/api/webhooks/install', $this->installPayload())->assertOk();
        $this->postJson('/api/webhooks/install', $this->installPayload())->assertOk();
        $this->postJson('/api/webhooks/install', $this->installPayload())->assertOk();

        Mail::assertSent(TemplateMail::class, 1);

        // ... and the repeats must not inflate the install counter either.
        $this->assertSame(1, Installation::firstWhere('store_url', 'test-store.myshopify.com')->install_count);
    }

    public function test_genuine_reinstall_sends_the_email_again_and_counts_the_install(): void
    {
        $this->postJson('/api/webhooks/install', $this->installPayload())->assertOk();
        $this->postJson('/api/webhooks/uninstall', [
            'app_url' => $this->shopifyApp->app_url,
            'shop_domain' => 'test-store.myshopify.com',
        ])->assertOk();

        // Push the previous send outside the duplicate-suppression window.
        Installation::firstWhere('store_url', 'test-store.myshopify.com')
            ->update(['install_email_sent_at' => now()->subDays(2)]);

        $this->postJson('/api/webhooks/install', $this->installPayload())->assertOk();

        $installation = Installation::firstWhere('store_url', 'test-store.myshopify.com');

        $this->assertTrue($installation->is_active);
        $this->assertSame(2, $installation->install_count);

        // install + uninstall + reinstall
        Mail::assertSent(TemplateMail::class, 3);
    }

    public function test_repeated_uninstall_webhooks_do_not_resend_the_email(): void
    {
        $this->postJson('/api/webhooks/install', $this->installPayload())->assertOk();

        $uninstall = [
            'app_url' => $this->shopifyApp->app_url,
            'shop_domain' => 'test-store.myshopify.com',
        ];

        $this->postJson('/api/webhooks/uninstall', $uninstall)->assertOk();
        $this->postJson('/api/webhooks/uninstall', $uninstall)->assertOk();

        // install + a single uninstall
        Mail::assertSent(TemplateMail::class, 2);
    }

    public function test_repeated_install_webhooks_do_not_overwrite_the_stored_plan(): void
    {
        $this->postJson('/api/webhooks/install', $this->installPayload())->assertOk();

        // A paid plan arrives after the install, as it does in production.
        $this->postJson('/api/webhooks/plan-change', [
            'app_url' => $this->shopifyApp->app_url,
            'shop_domain' => 'test-store.myshopify.com',
            'app_plan' => ['plan_name' => 'Premium', 'status' => 'ACTIVE'],
        ])->assertOk();

        // The install webhook is then re-delivered, with a thinner payload.
        $this->postJson('/api/webhooks/install', [
            'app_url' => $this->shopifyApp->app_url,
            'shop_domain' => 'test-store.myshopify.com',
            'name' => 'Test Store',
            'email' => 'owner@test-store.example.com',
        ])->assertOk();

        $installation = Installation::firstWhere('store_url', 'test-store.myshopify.com');

        $this->assertSame('Premium', $installation->app_plan['plan_name']);
        // Fields the repeat delivery left out must survive too.
        $this->assertSame('Basic', $installation->shopify_plan);
        $this->assertSame('USD', $installation->currency);
    }

    public function test_a_get_on_a_post_only_webhook_is_answered_with_a_diagnosable_405(): void
    {
        // This is what the app side saw: a redirect had rewritten its POST.
        $response = $this->getJson('/api/webhooks/install');

        $response->assertStatus(405)
            ->assertHeader('Allow', 'POST')
            ->assertJson(['success' => false])
            ->assertJsonStructure(['message', 'hint']);
    }

    public function test_unknown_webhook_endpoint_lists_the_known_ones(): void
    {
        $this->postJson('/api/webhooks/installs')
            ->assertStatus(404)
            ->assertJsonStructure(['message', 'known_endpoints']);
    }

    public function test_ping_reports_reachability(): void
    {
        $this->getJson('/api/webhooks/ping')
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['endpoints' => ['install', 'uninstall', 'plan-change']]);
    }

    public function test_install_for_an_unconnected_app_is_rejected_without_email(): void
    {
        $this->postJson('/api/webhooks/install', $this->installPayload([
            'app_url' => 'https://not-connected.example.com',
        ]))->assertStatus(404);

        Mail::assertNothingSent();
    }
}
