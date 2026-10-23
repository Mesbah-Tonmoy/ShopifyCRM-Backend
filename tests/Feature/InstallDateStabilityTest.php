<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Installation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * installed_at is the date a store installed the app. It is written when the
 * store is first recorded, and again only when a store that had uninstalled
 * comes back.
 *
 * Production had install dates walking forward week by week on stores with no
 * reinstall at all: the app re-sends the install webhook outside a real
 * install and reports installed_at as the moment of the call, and both the
 * webhook and the app sync wrote whatever they were handed.
 */
class InstallDateStabilityTest extends TestCase
{
    use RefreshDatabase;

    private App $shopifyApp;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);

        $role = Role::create(['name' => 'App admin', 'slug' => 'app-admin']);
        $role->permissions()->attach(Permission::create(['name' => 'Apps Add', 'slug' => 'apps.add']));

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($role);

        $this->shopifyApp = App::create([
            'app_name' => 'Test App',
            'app_url' => 'https://test-app.example.com',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function installWebhook(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/webhooks/install', array_merge([
            'app_url' => $this->shopifyApp->app_url,
            'shop_domain' => 'gonna-days.myshopify.com',
            'name' => 'GONNA DAYS',
            'email' => 'info@gonnadays.example',
            'shop_owner_name' => 'gonna-days.myshopify.com',
        ], $overrides));
    }

    private function installation(): Installation
    {
        return Installation::firstWhere('store_url', 'gonna-days.myshopify.com');
    }

    /* -----------------------------------------------------------------
     | The install webhook
     | -----------------------------------------------------------------
     */

    public function test_a_repeat_webhook_does_not_move_the_install_date(): void
    {
        $this->installWebhook(['installed_at' => '2026-10-16 02:31:49'])->assertOk();

        $this->assertSame('2026-10-16 02:31:49', $this->installation()->installed_at->toDateTimeString());

        // What the live app does: re-sends on a later authentication, giving
        // the moment of the call as the install date.
        $this->installWebhook(['installed_at' => '2026-10-23 06:17:24'])->assertOk();

        $installation = $this->installation();

        $this->assertSame(
            '2026-10-16 02:31:49',
            $installation->installed_at->toDateTimeString(),
            'A repeat delivery must not rewrite the install date.'
        );
        $this->assertSame(1, $installation->install_count);
    }

    public function test_a_genuine_reinstall_does_move_the_install_date(): void
    {
        $this->installWebhook(['installed_at' => '2026-10-16 02:31:49'])->assertOk();

        $this->postJson('/api/webhooks/uninstall', [
            'app_url' => $this->shopifyApp->app_url,
            'shop_domain' => 'gonna-days.myshopify.com',
        ])->assertOk();

        $this->installWebhook(['installed_at' => '2026-10-23 06:17:24'])->assertOk();

        $installation = $this->installation();

        $this->assertSame('2026-10-23 06:17:24', $installation->installed_at->toDateTimeString());
        $this->assertSame(2, $installation->install_count);
    }

    public function test_a_row_without_an_install_date_is_still_backfilled(): void
    {
        $this->installWebhook()->assertOk();

        $this->installation()->forceFill(['installed_at' => null])->save();

        $this->installWebhook(['installed_at' => '2026-10-16 02:31:49'])->assertOk();

        $this->assertSame('2026-10-16 02:31:49', $this->installation()->installed_at->toDateTimeString());
    }

    /* -----------------------------------------------------------------
     | The app sync (Connect App / Resync)
     | -----------------------------------------------------------------
     */

    /**
     * @param  array<string, mixed>  $store
     */
    private function fakeSync(array $store): void
    {
        Http::fake([
            '*/api/sync-crm' => Http::response([
                'success' => true,
                'appData' => ['title' => 'Test App'],
                'stores' => [array_merge([
                    'shopDomain' => 'gonna-days.myshopify.com',
                    'name' => 'GONNA DAYS',
                    'email' => 'info@gonnadays.example',
                    'currencyCode' => 'JPY',
                    'shopifyPlan' => 'Basic',
                    'appPlan' => 'Free',
                    'isActive' => true,
                ], $store)],
            ], 200),
        ]);
    }

    public function test_a_resync_does_not_move_an_install_date_it_already_has(): void
    {
        $this->installWebhook(['installed_at' => '2026-10-16 02:31:49'])->assertOk();

        $this->fakeSync(['installedAt' => '2026-10-23 06:17:24']);

        $this->actingAs($this->admin)->postJson("/api/apps/{$this->shopifyApp->id}/resync")->assertOk();

        $this->assertSame('2026-10-16 02:31:49', $this->installation()->installed_at->toDateTimeString());
    }

    public function test_a_resync_fills_an_install_date_that_is_missing(): void
    {
        $this->installWebhook()->assertOk();
        $this->installation()->forceFill(['installed_at' => null])->save();

        $this->fakeSync(['installedAt' => '2026-10-16 02:31:49']);

        $this->actingAs($this->admin)->postJson("/api/apps/{$this->shopifyApp->id}/resync")->assertOk();

        $this->assertSame('2026-10-16 02:31:49', $this->installation()->installed_at->toDateTimeString());
    }

    public function test_a_resync_keeps_the_owner_name_and_reinstall_count(): void
    {
        $this->installWebhook(['installed_at' => '2026-10-16 02:31:49'])->assertOk();

        $this->installation()->forceFill(['install_count' => 3])->save();

        // The app reports neither of these, and used to blank both.
        $this->fakeSync([]);

        $this->actingAs($this->admin)->postJson("/api/apps/{$this->shopifyApp->id}/resync")->assertOk();

        $installation = $this->installation();

        $this->assertSame('gonna-days.myshopify.com', $installation->shop_owner_name);
        $this->assertSame(3, $installation->install_count);
    }

    public function test_a_resync_still_updates_what_the_app_is_authoritative_for(): void
    {
        $this->installWebhook(['installed_at' => '2026-10-16 02:31:49'])->assertOk();

        $this->fakeSync(['shopifyPlan' => 'Shopify Plus', 'appPlan' => 'Premium', 'isActive' => false]);

        $this->actingAs($this->admin)->postJson("/api/apps/{$this->shopifyApp->id}/resync")->assertOk();

        $installation = $this->installation();

        $this->assertSame('Shopify Plus', $installation->shopify_plan);
        $this->assertSame('Premium', $installation->app_plan['plan_name']);
        $this->assertFalse($installation->is_active);
    }
}
