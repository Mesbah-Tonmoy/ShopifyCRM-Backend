<?php

namespace Tests\Feature\Board;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBoardFixtures;
use Tests\TestCase;

/**
 * The address lists behind the new-request heads-up, as the settings screen
 * writes them.
 */
class BoardRecipientValidationTest extends TestCase
{
    use BuildsBoardFixtures;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'Board admin', 'slug' => 'board-admin']);
        $role->permissions()->attach(Permission::create(['name' => 'Board', 'slug' => 'board_settings.edit']));

        $this->user = User::factory()->create();
        $this->user->roles()->attach($role);
    }

    public function test_it_accepts_and_normalises_comma_separated_lists(): void
    {
        $app = $this->makeApp();

        $response = $this->actingAs($this->user)->putJson("/api/apps/{$app->id}/board", [
            'new_request_email' => ' team@example.test ,product@example.test , ',
            'new_request_cc' => 'support@example.test',
            'new_request_bcc' => '',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.settings.new_request_email', 'team@example.test, product@example.test')
            ->assertJsonPath('data.settings.new_request_cc', 'support@example.test')
            ->assertJsonPath('data.settings.new_request_bcc', null);
    }

    public function test_it_names_the_address_that_is_wrong(): void
    {
        $app = $this->makeApp();

        $response = $this->actingAs($this->user)->putJson("/api/apps/{$app->id}/board", [
            'new_request_email' => 'team@example.test, not-an-address, product@example.test',
        ]);

        $response->assertStatus(422);

        $this->assertStringContainsString(
            'not-an-address',
            $response->json('errors.new_request_email.0'),
            'The message should point at the offending entry, not just the field.'
        );
    }

    public function test_cc_and_bcc_are_validated_too(): void
    {
        $app = $this->makeApp();

        $this->actingAs($this->user)->putJson("/api/apps/{$app->id}/board", [
            'new_request_cc' => 'broken@',
        ])->assertStatus(422)->assertJsonValidationErrors('new_request_cc');

        $this->actingAs($this->user)->putJson("/api/apps/{$app->id}/board", [
            'new_request_bcc' => 'also broken',
        ])->assertStatus(422)->assertJsonValidationErrors('new_request_bcc');
    }

    public function test_clearing_the_to_list_switches_the_heads_up_off(): void
    {
        $app = $this->makeApp(['new_request_email' => 'team@example.test']);

        $this->actingAs($this->user)->putJson("/api/apps/{$app->id}/board", [
            'new_request_email' => '',
        ])->assertOk()->assertJsonPath('data.settings.new_request_email', null);

        $this->assertSame([], $app->board->fresh()->newRequestAudience()['to']);
    }

    public function test_an_overlong_list_is_rejected(): void
    {
        $app = $this->makeApp();

        $addresses = collect(range(1, 25))->map(fn (int $i) => "person{$i}@example.test")->implode(', ');

        $this->actingAs($this->user)->putJson("/api/apps/{$app->id}/board", [
            'new_request_email' => $addresses,
        ])->assertStatus(422)->assertJsonValidationErrors('new_request_email');
    }
}
