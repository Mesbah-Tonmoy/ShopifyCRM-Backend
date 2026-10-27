<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\GoogleChatSpace;
use App\Models\Integration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GoogleChatIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_URL = 'https://chat.googleapis.com/v1/spaces/AAAA/messages?key=k&token=t';

    private const FORMCRM_SPACE = 'https://chat.googleapis.com/v1/spaces/FORM/messages?key=k&token=f';

    private const ZAPIO_SPACE = 'https://chat.googleapis.com/v1/spaces/ZAP/messages?key=k&token=z';

    private App $formCrm;

    private App $zapio;

    private App $otherApp;

    /**
     * What the faked Google Chat API answers with; a test replaces it to
     * simulate Google rejecting a message.
     */
    private mixed $chatResponse;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);

        Mail::fake();
        Http::preventStrayRequests();
        $this->chatResponse = Http::response(['name' => 'spaces/AAAA/messages/1']);
        Http::fake(['chat.googleapis.com/*' => fn () => $this->chatResponse]);

        $this->formCrm = App::create(['app_name' => 'FormCRM', 'app_url' => 'https://formcrm.example.com']);
        $this->zapio = App::create(['app_name' => 'Zapio', 'app_url' => 'https://zapio.example.com']);
        $this->otherApp = App::create(['app_name' => 'Other App', 'app_url' => 'https://other.example.com']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function addSpace(array $attributes = []): GoogleChatSpace
    {
        return GoogleChatSpace::create(array_merge([
            'name' => 'Installs',
            'webhook_url' => self::WEBHOOK_URL,
            'events' => ['new_installation'],
            'app_scope' => 'selected',
            'app_ids' => [$this->formCrm->id, $this->zapio->id],
            'is_enabled' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function spacePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'FormCRM',
            'webhook_url' => self::FORMCRM_SPACE,
            'events' => ['new_installation'],
            'app_scope' => 'selected',
            'app_ids' => [$this->formCrm->id],
            'is_enabled' => true,
        ], $overrides);
    }

    private function install(App $app, string $shop = 'acme.myshopify.com')
    {
        return $this->postJson('/api/webhooks/install', [
            'app_url' => $app->app_url,
            'shop_domain' => $shop,
            'name' => 'Acme Store',
            'email' => 'owner@acme.example.com',
            'shopify_plan' => 'Basic',
        ])->assertOk();
    }

    private function uninstall(App $app, string $shop = 'acme.myshopify.com')
    {
        return $this->postJson('/api/webhooks/uninstall', [
            'app_url' => $app->app_url,
            'shop_domain' => $shop,
        ])->assertOk();
    }

    private function adminWith(string ...$permissions): User
    {
        $role = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        foreach ($permissions as $slug) {
            $role->permissions()->attach(Permission::create(['name' => $slug, 'slug' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    // --- Notifications ---------------------------------------------------

    public function test_new_install_of_a_selected_app_posts_to_google_chat(): void
    {
        $this->addSpace();

        $this->install($this->formCrm);
        $this->install($this->zapio, 'beta.myshopify.com');

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::WEBHOOK_URL
            && str_contains($request['text'], '*New installation* · FormCRM')
            && str_contains($request['text'], 'Store: Acme Store (acme.myshopify.com)')
            && str_contains($request['text'], 'Email: owner@acme.example.com')
            && str_contains($request['text'], 'Shopify plan: Basic'));
        Http::assertSent(fn (HttpRequest $request) => str_contains($request['text'], '*New installation* · Zapio'));
    }

    public function test_each_app_posts_to_its_own_space(): void
    {
        $this->addSpace(['name' => 'FormCRM', 'webhook_url' => self::FORMCRM_SPACE, 'app_ids' => [$this->formCrm->id]]);
        $this->addSpace(['name' => 'Zapio', 'webhook_url' => self::ZAPIO_SPACE, 'app_ids' => [$this->zapio->id]]);

        $this->install($this->formCrm);
        $this->install($this->zapio);
        $this->install($this->otherApp);

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::FORMCRM_SPACE
            && str_contains($request['text'], '· FormCRM'));
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::ZAPIO_SPACE
            && str_contains($request['text'], '· Zapio'));
        Http::assertNotSent(fn (HttpRequest $request) => $request->url() === self::FORMCRM_SPACE
            && str_contains($request['text'], '· Zapio'));
    }

    public function test_spaces_choose_their_own_events(): void
    {
        $this->addSpace(['webhook_url' => self::FORMCRM_SPACE, 'events' => ['new_installation'], 'app_ids' => [$this->formCrm->id]]);
        $this->addSpace(['webhook_url' => self::ZAPIO_SPACE, 'events' => ['uninstallation'], 'app_ids' => [$this->formCrm->id]]);

        $this->install($this->formCrm);
        $this->uninstall($this->formCrm);

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::FORMCRM_SPACE
            && str_contains($request['text'], '*New installation*'));
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::ZAPIO_SPACE
            && str_contains($request['text'], '*Uninstallation*'));
    }

    public function test_every_matching_space_is_posted_to_but_a_shared_webhook_only_once(): void
    {
        $this->addSpace(['name' => 'Per app', 'webhook_url' => self::FORMCRM_SPACE, 'app_ids' => [$this->formCrm->id]]);
        $this->addSpace(['name' => 'Everything', 'webhook_url' => self::WEBHOOK_URL, 'app_scope' => 'all', 'app_ids' => []]);
        $this->addSpace(['name' => 'Duplicate', 'webhook_url' => self::WEBHOOK_URL, 'app_scope' => 'all', 'app_ids' => []]);

        $this->install($this->formCrm);

        Http::assertSentCount(2);
    }

    public function test_install_of_an_app_that_is_not_selected_is_not_posted(): void
    {
        $this->addSpace();

        $this->install($this->otherApp);

        Http::assertNothingSent();
    }

    public function test_all_apps_scope_posts_for_any_app(): void
    {
        $this->addSpace(['app_scope' => 'all', 'app_ids' => []]);

        $this->install($this->otherApp);

        Http::assertSentCount(1);
    }

    public function test_repeat_install_webhooks_post_only_once(): void
    {
        $this->addSpace();

        $this->install($this->formCrm);
        $this->install($this->formCrm);
        $this->install($this->formCrm);

        Http::assertSentCount(1);
    }

    public function test_a_disabled_space_posts_nothing_while_others_still_do(): void
    {
        $this->addSpace(['webhook_url' => self::FORMCRM_SPACE, 'is_enabled' => false]);
        $this->addSpace(['webhook_url' => self::ZAPIO_SPACE]);

        $this->install($this->formCrm);

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::ZAPIO_SPACE);
    }

    public function test_events_that_are_not_selected_are_not_posted(): void
    {
        $this->addSpace(['events' => ['new_installation']]);

        $this->install($this->formCrm);
        $this->uninstall($this->formCrm);
        $this->install($this->formCrm); // reinstall

        Http::assertSentCount(1);
    }

    public function test_reinstall_and_uninstall_post_when_selected(): void
    {
        $this->addSpace(['events' => ['reinstallation', 'uninstallation']]);

        $this->install($this->formCrm);
        $this->uninstall($this->formCrm);
        $this->uninstall($this->formCrm); // repeat delivery
        $this->install($this->formCrm);

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request['text'], '*Uninstallation* · FormCRM'));
        Http::assertSent(fn (HttpRequest $request) => str_contains($request['text'], '*Reinstallation* · FormCRM')
            && str_contains($request['text'], 'Install count: 2'));
    }

    public function test_plan_change_posts_when_selected(): void
    {
        $this->addSpace(['events' => ['plan_change']]);
        $this->install($this->formCrm);

        $this->postJson('/api/webhooks/plan-change', [
            'app_url' => $this->formCrm->app_url,
            'shop_domain' => 'acme.myshopify.com',
            'app_plan' => ['plan_name' => 'Pro', 'status' => 'ACTIVE'],
        ])->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request['text'], '*Plan change* · FormCRM')
            && str_contains($request['text'], 'Plan: Pro')
            && str_contains($request['text'], 'Status: ACTIVE'));
    }

    public function test_a_google_chat_failure_does_not_fail_the_webhook(): void
    {
        $this->chatResponse = Http::response('boom', 500);
        $this->addSpace();

        $this->install($this->formCrm)->assertJson(['success' => true]);

        Http::assertSentCount(1); // it was attempted, and Google failed it
        $this->assertDatabaseHas('installations', ['store_url' => 'acme.myshopify.com', 'is_active' => true]);
    }

    // --- Spaces API ------------------------------------------------------

    public function test_spaces_can_be_added_listed_updated_and_removed(): void
    {
        $user = $this->adminWith('integrations.view', 'integrations.edit');

        $formCrm = $this->actingAs($user)->postJson('/api/integrations/google-chat/spaces', $this->spacePayload([
            'events' => ['new_installation', 'new_installation'],
            'app_ids' => [$this->formCrm->id, (string) $this->formCrm->id],
        ]))->assertCreated()->json('data');

        $this->actingAs($user)->postJson('/api/integrations/google-chat/spaces', $this->spacePayload([
            'name' => 'Zapio',
            'webhook_url' => self::ZAPIO_SPACE,
            'app_ids' => [$this->zapio->id],
        ]))->assertCreated();

        $space = GoogleChatSpace::findOrFail($formCrm['id']);
        $this->assertSame(['new_installation'], $space->events);
        $this->assertSame([$this->formCrm->id], $space->app_ids);
        // The webhook URL carries the space's token, so it is stored encrypted.
        $this->assertNotSame(self::FORMCRM_SPACE, DB::table('google_chat_spaces')->where('id', $space->id)->value('webhook_url'));

        $this->actingAs($user)->getJson('/api/integrations/google-chat/spaces')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'FormCRM')
            ->assertJsonPath('data.0.webhook_url', self::FORMCRM_SPACE)
            ->assertJsonPath('data.1.name', 'Zapio');

        $this->actingAs($user)->putJson("/api/integrations/google-chat/spaces/{$space->id}", $this->spacePayload([
            'name' => 'FormCRM installs',
            'events' => ['new_installation', 'uninstallation'],
        ]))->assertOk()->assertJsonPath('data.name', 'FormCRM installs');
        $this->assertSame(['new_installation', 'uninstallation'], $space->fresh()->events);

        $this->actingAs($user)->deleteJson("/api/integrations/google-chat/spaces/{$space->id}")->assertOk();
        $this->assertNull($space->fresh());
        $this->assertSame(1, GoogleChatSpace::count());
    }

    public function test_spaces_reject_invalid_settings(): void
    {
        $user = $this->adminWith('integrations.edit');
        $post = fn (array $overrides) => $this->actingAs($user)
            ->postJson('/api/integrations/google-chat/spaces', $this->spacePayload($overrides));

        // Only Google Chat webhooks: the URL must not point the server elsewhere.
        $post(['webhook_url' => 'https://evil.example.com/hook'])->assertStatus(422)->assertJsonValidationErrors('webhook_url');
        $post(['webhook_url' => ''])->assertStatus(422)->assertJsonValidationErrors('webhook_url');
        $post(['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
        $post(['events' => ['nonsense']])->assertStatus(422)->assertJsonValidationErrors('events.0');
        $post(['app_ids' => [999999]])->assertStatus(422)->assertJsonValidationErrors('app_ids.0');
        $post(['app_scope' => 'some'])->assertStatus(422)->assertJsonValidationErrors('app_scope');

        // An enabled space needs events, and apps when limited to specific ones...
        $post(['events' => []])->assertStatus(422)->assertJsonValidationErrors('events');
        $post(['app_ids' => []])->assertStatus(422)->assertJsonValidationErrors('app_ids');

        $this->assertSame(0, GoogleChatSpace::count());

        // ...but a disabled one can be saved half-done.
        $post(['events' => [], 'app_ids' => [], 'is_enabled' => false])->assertCreated();
    }

    public function test_spaces_require_permissions(): void
    {
        $viewer = $this->adminWith('integrations.view');
        $space = $this->addSpace();

        $this->actingAs($viewer)->getJson('/api/integrations/google-chat/spaces')->assertOk();
        $this->actingAs($viewer)->postJson('/api/integrations/google-chat/spaces', $this->spacePayload())->assertForbidden();
        $this->actingAs($viewer)->putJson("/api/integrations/google-chat/spaces/{$space->id}", $this->spacePayload())->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/integrations/google-chat/spaces/{$space->id}")->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/integrations/google-chat/test', ['webhook_url' => self::WEBHOOK_URL])->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(1, GoogleChatSpace::count());
    }

    public function test_options_list_the_events_and_apps(): void
    {
        $user = $this->adminWith('integrations.view');

        $this->actingAs($user)->getJson('/api/integrations/google-chat/options')
            ->assertOk()
            ->assertJsonFragment(['key' => 'new_installation', 'label' => 'New installation'])
            ->assertJsonFragment(['id' => $this->formCrm->id, 'app_name' => 'FormCRM'])
            ->assertJsonFragment(['id' => $this->zapio->id, 'app_name' => 'Zapio']);
    }

    public function test_test_message_goes_to_the_given_url(): void
    {
        $user = $this->adminWith('integrations.edit');

        $this->actingAs($user)->postJson('/api/integrations/google-chat/test', ['webhook_url' => self::WEBHOOK_URL])
            ->assertOk()->assertJson(['success' => true]);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::WEBHOOK_URL
            && str_contains($request['text'], 'Test message'));

        $this->actingAs($user)->postJson('/api/integrations/google-chat/test', ['webhook_url' => 'https://evil.example.com/hook'])
            ->assertStatus(422);
        Http::assertSentCount(1);
    }

    public function test_test_message_reports_a_rejected_webhook(): void
    {
        $this->chatResponse = Http::response(['error' => 'bad token'], 400);
        $user = $this->adminWith('integrations.edit');

        $this->actingAs($user)->postJson('/api/integrations/google-chat/test', ['webhook_url' => self::WEBHOOK_URL])
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    // --- Upgrade from the single-space config -----------------------------

    public function test_migration_turns_the_old_single_config_into_a_space(): void
    {
        Integration::create([
            'key' => 'google_chat',
            'name' => 'Google Chat',
            'is_enabled' => true,
            'config' => [
                'webhook_url' => self::WEBHOOK_URL,
                'events' => ['uninstallation', 'reinstallation'],
                'app_scope' => 'all',
                'app_ids' => [],
            ],
        ]);

        (require database_path('migrations/2026_10_27_000000_create_google_chat_spaces_table.php'))->moveLegacyConfig();

        $space = GoogleChatSpace::sole();
        $this->assertSame('Google Chat', $space->name);
        $this->assertSame(self::WEBHOOK_URL, $space->webhook_url);
        $this->assertSame(['uninstallation', 'reinstallation'], $space->events);
        $this->assertSame('all', $space->app_scope);
        $this->assertTrue($space->is_enabled);
        $this->assertNull(Integration::findByKey('google_chat'));

        // ...and it keeps notifying as before.
        $this->install($this->otherApp);
        $this->uninstall($this->otherApp);
        Http::assertSentCount(1);
    }
}
