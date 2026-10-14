<?php

namespace Tests\Feature\Board;

use App\Models\App;
use App\Models\FeatureRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBoardFixtures;
use Tests\TestCase;

/**
 * The admin list's date range.
 *
 * Same shape as the installation list's: the bounds are calendar days in the
 * caller's timezone, because that is the zone the dates on screen were
 * rendered in.
 */
class FeatureRequestDateFilterTest extends TestCase
{
    use BuildsBoardFixtures;
    use RefreshDatabase;

    private User $user;

    /** One app for the whole test: makeApp() pins unique board credentials. */
    private ?App $shopifyApp = null;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'Requests', 'slug' => 'requests']);
        $role->permissions()->attach(Permission::create(['name' => 'View', 'slug' => 'feature_requests.view']));

        $this->user = User::factory()->create();
        $this->user->roles()->attach($role);
    }

    private function requestCreatedAt(string $title, string $createdAt): FeatureRequest
    {
        $app = $this->shopifyApp ??= $this->makeApp();

        $model = $this->makeRequest($app, null, ['title' => $title]);
        $model->forceFill(['created_at' => $createdAt])->save();

        return $model;
    }

    /**
     * @return array<int, string>
     */
    private function titlesFor(array $query): array
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/feature-requests?' . http_build_query($query))
            ->assertOk();

        return array_column($response->json('data.data'), 'title');
    }

    /**
     * Regression: the range compared UTC calendar days against days the
     * browser had rendered in the viewer's zone, so east of UTC a late-evening
     * request sat on the neighbouring day at one end of the range and fell out
     * of the other.
     */
    public function test_the_range_reads_the_dates_in_the_callers_timezone(): void
    {
        // 02:30 on the 13th in Dhaka; 20:30 on the 12th in UTC.
        $this->requestCreatedAt('Filed after midnight in Dhaka', '2026-10-12 20:30:00');
        // 01:00 on the 6th in Dhaka; 19:00 on the 5th in UTC.
        $this->requestCreatedAt('Filed after midnight on the 6th', '2026-10-05 19:00:00');
        $this->requestCreatedAt('Filed mid-range', '2026-10-09 09:00:00');

        $titles = $this->titlesFor([
            'date_from' => '2026-10-06',
            'date_to' => '2026-10-12',
            'timezone' => 'Asia/Dhaka',
        ]);

        $this->assertContains('Filed mid-range', $titles);
        // Reads as the 6th in Dhaka, so it belongs in a range starting then.
        $this->assertContains('Filed after midnight on the 6th', $titles);
        // Reads as the 13th in Dhaka, so it is past a range ending the 12th.
        $this->assertNotContains('Filed after midnight in Dhaka', $titles);
    }

    public function test_the_same_range_in_utc_keeps_the_utc_days(): void
    {
        $this->requestCreatedAt('Late on the twelfth', '2026-10-12 20:30:00');
        $this->requestCreatedAt('Late on the fifth', '2026-10-05 19:00:00');

        $titles = $this->titlesFor([
            'date_from' => '2026-10-06',
            'date_to' => '2026-10-12',
            'timezone' => 'UTC',
        ]);

        $this->assertContains('Late on the twelfth', $titles);
        $this->assertNotContains('Late on the fifth', $titles);
    }

    public function test_the_end_of_the_range_is_inclusive_to_the_last_second(): void
    {
        $this->requestCreatedAt('One second to midnight', '2026-10-12 23:59:59');

        $this->assertContains('One second to midnight', $this->titlesFor([
            'date_from' => '2026-10-12',
            'date_to' => '2026-10-12',
            'timezone' => 'UTC',
        ]));
    }

    public function test_an_unknown_timezone_falls_back_instead_of_erroring(): void
    {
        $this->requestCreatedAt('Anything', '2026-10-09 09:00:00');

        $this->assertContains('Anything', $this->titlesFor([
            'date_from' => '2026-01-01',
            'date_to' => '2036-01-01',
            'timezone' => 'Moon/Base',
        ]));
    }

    public function test_a_malformed_date_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/feature-requests?date_from=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_from');
    }
}
