<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Installation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallationListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private App $appA;

    private App $appB;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'Viewer', 'slug' => 'viewer']);
        $role->permissions()->attach(Permission::create(['name' => 'View', 'slug' => 'installations.view']));
        $this->user = User::factory()->create();
        $this->user->roles()->attach($role);

        $this->appA = App::create(['app_name' => 'App A', 'app_url' => 'https://a.example.com']);
        $this->appB = App::create(['app_name' => 'App B', 'app_url' => 'https://b.example.com']);

        $this->install($this->appA, 'a1', 'Free', 'Basic');
        $this->install($this->appA, 'a2', 'Pro', 'Basic');
        $this->install($this->appA, 'a3', 'Free', 'Advanced', false);
        $this->install($this->appB, 'b1', 'Enterprise', 'Plus');
        $this->install($this->appB, 'b2', null, 'Developer Preview');
    }

    private function install(App $app, string $slug, ?string $plan, string $shopifyPlan, bool $active = true): Installation
    {
        return Installation::create([
            'app_id' => $app->id,
            'store_name' => "Store {$slug}",
            'store_url' => "{$slug}.myshopify.com",
            'email' => "{$slug}@example.com",
            'shopify_plan' => $shopifyPlan,
            'app_plan' => $plan ? ['plan_name' => $plan] : null,
            'is_active' => $active,
            'install_count' => 1,
            'installed_at' => now(),
        ]);
    }

    public function test_the_dashboard_api_cannot_write_installations(): void
    {
        // Installations are written only by the Shopify apps, via webhooks.
        $installation = Installation::firstWhere('store_url', 'a1.myshopify.com');

        $this->actingAs($this->user)->postJson('/api/installations', [
            'app_id' => $this->appA->id,
            'store_name' => 'New',
            'store_url' => 'new.myshopify.com',
            'email' => 'new@example.com',
        ])->assertMethodNotAllowed();
        // No /installations/{installation} route exists at all, for any verb:
        // the list is the dashboard's only read.
        foreach (['getJson', 'putJson', 'deleteJson'] as $call) {
            $this->actingAs($this->user)->{$call}("/api/installations/{$installation->id}")
                ->assertNotFound();
        }

        $this->assertSame('Store a1', $installation->fresh()->store_name);
        $this->assertDatabaseMissing('installations', ['store_url' => 'new.myshopify.com']);
    }

    public function test_filter_options_cover_every_app_by_default(): void
    {
        $this->actingAs($this->user)->getJson('/api/installations/filters')
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => [
                'shopify_plans' => ['Advanced', 'Basic', 'Developer Preview', 'Plus'],
                'app_plans' => ['Enterprise', 'Free', 'Pro'],
            ]]);
    }

    public function test_filter_options_are_scoped_to_one_app(): void
    {
        $this->actingAs($this->user)->getJson('/api/installations/filters?app_id=' . $this->appA->id)
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => [
                'shopify_plans' => ['Advanced', 'Basic'],
                'app_plans' => ['Free', 'Pro'],
            ]]);
    }

    public function test_filters_combine_with_app_and_paginate_from_page_one(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/installations?' . http_build_query([
            'app_id' => $this->appA->id,
            'plan_name' => ['Free'],
            'shopify_plans' => ['Basic', 'Advanced'],
            'is_active' => 1,
            'per_page' => 1,
            'page' => 1,
        ]));

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.store_url', 'a1.myshopify.com');
    }

    public function test_a_cleared_install_count_box_does_not_filter(): void
    {
        // A cleared box arrives as an empty string; it used to mean "<= 0".
        $this->actingAs($this->user)->getJson('/api/installations?install_count_min=&install_count_max=')
            ->assertOk()
            ->assertJsonPath('data.total', 5);
    }

    public function test_sorting_by_plan_rejects_injected_sort_order(): void
    {
        // sort_order used to be concatenated into raw SQL here: this payload
        // cut the page query to zero rows while the count still said 5.
        $this->actingAs($this->user)->getJson('/api/installations?' . http_build_query([
            'sort_by' => 'app_plan',
            'sort_order' => 'asc LIMIT 0 -- ',
        ]))->assertOk()->assertJsonCount(5, 'data.data');
    }

    public function test_unknown_sort_column_falls_back_instead_of_erroring(): void
    {
        $this->actingAs($this->user)->getJson('/api/installations?sort_by=not_a_column')
            ->assertOk()
            ->assertJsonPath('data.total', 5);
    }

    public function test_sorting_by_app_name_still_works(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/installations?sort_by=app_name&sort_order=desc')
            ->assertOk();

        $this->assertSame('App B', $response->json('data.data.0.app.app_name'));
    }
}
