<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Installation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The installation list's CSV export.
 *
 * The point of the endpoint is that it is not bounded by the page the user
 * happens to be looking at, so most of these check the row count against the
 * filters rather than against per_page.
 */
class InstallationExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private App $appA;

    private App $appB;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'Exporter', 'slug' => 'exporter']);
        $role->permissions()->attach(Permission::create(['name' => 'View', 'slug' => 'installations.view']));
        $role->permissions()->attach(Permission::create(['name' => 'Export', 'slug' => 'installations.export']));

        $this->user = User::factory()->create();
        $this->user->roles()->attach($role);

        $this->appA = App::create(['app_name' => 'App A', 'app_url' => 'https://a.example.com']);
        $this->appB = App::create(['app_name' => 'App B', 'app_url' => 'https://b.example.com']);
    }

    private function install(App $app, string $slug, array $attributes = []): Installation
    {
        return Installation::create(array_merge([
            'app_id' => $app->id,
            'store_name' => "Store {$slug}",
            'store_url' => "{$slug}.myshopify.com",
            'email' => "{$slug}@example.com",
            'shopify_plan' => 'Basic',
            'app_plan' => ['plan_name' => 'Free'],
            'is_active' => true,
            'install_count' => 1,
            'installed_at' => '2026-10-10 09:00:00',
        ], $attributes));
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function csvRows(string $body): array
    {
        // The BOM is for Excel; it is not part of the first header cell.
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);

        $rows = array_map(
            fn (string $line) => str_getcsv($line, ',', '"', ''),
            preg_split("/\r?\n/", trim($body)) ?: []
        );

        return array_values(array_filter($rows, fn (array $row) => $row !== [null] && $row !== ['']));
    }

    private function export(array $query = []): array
    {
        $response = $this->actingAs($this->user)
            ->get('/api/installations/export?' . http_build_query($query));

        $response->assertOk();

        return $this->csvRows($response->streamedContent());
    }

    public function test_it_exports_every_matching_row_not_just_one_page(): void
    {
        foreach (range(1, 30) as $i) {
            $this->install($this->appA, "store{$i}");
        }

        // per_page is what the list is paginated by; the export must ignore it.
        $rows = $this->export(['per_page' => 10]);

        // 30 stores plus the header row.
        $this->assertCount(31, $rows);
    }

    public function test_it_applies_the_same_filters_as_the_list(): void
    {
        $this->install($this->appA, 'keep-a');
        $this->install($this->appA, 'keep-b');
        $this->install($this->appB, 'other');
        $this->install($this->appA, 'inactive', ['is_active' => false]);

        $rows = $this->export(['app_id' => $this->appA->id, 'is_active' => 1]);
        $stores = array_column(array_slice($rows, 1), 2);

        $this->assertCount(2, $stores);
        $this->assertContains('keep-a.myshopify.com', $stores);
        $this->assertContains('keep-b.myshopify.com', $stores);
        $this->assertNotContains('other.myshopify.com', $stores);
        $this->assertNotContains('inactive.myshopify.com', $stores);
    }

    public function test_the_date_range_is_read_in_the_callers_timezone(): void
    {
        // 02:30 on the 13th in Dhaka; 20:30 on the 12th in UTC.
        $this->install($this->appA, 'late', ['installed_at' => '2026-10-12 20:30:00']);
        $this->install($this->appA, 'inside', ['installed_at' => '2026-10-10 09:00:00']);

        $stores = array_column(array_slice($this->export([
            'date_from' => '2026-10-06',
            'date_to' => '2026-10-12',
            'timezone' => 'Asia/Dhaka',
        ]), 1), 2);

        $this->assertSame(['inside.myshopify.com'], $stores);
    }

    public function test_install_dates_are_written_in_the_callers_timezone(): void
    {
        $this->install($this->appA, 'late', ['installed_at' => '2026-10-12 20:30:00']);

        $utc = $this->export(['timezone' => 'UTC']);
        $dhaka = $this->export(['timezone' => 'Asia/Dhaka']);

        // The exported date has to read like the one on screen, which the
        // browser renders in its own zone. Column 8 is "Installed".
        $this->assertSame('Oct 12, 2026', $utc[1][8]);
        $this->assertSame('Oct 13, 2026', $dhaka[1][8]);
    }

    /**
     * The old client-side writer only quoted fields containing a comma, so a
     * quote or a newline in a store name split the row.
     */
    public function test_it_escapes_separators_and_quotes_in_the_data(): void
    {
        $this->install($this->appA, 'awkward', [
            'store_name' => 'Smith, Jones & Co "The Shop"',
        ]);

        $rows = $this->export();

        $this->assertCount(2, $rows, 'An escaped field must not split the row.');
        $this->assertSame('Smith, Jones & Co "The Shop"', $rows[1][1]);
    }

    public function test_the_header_names_every_column(): void
    {
        $this->install($this->appA, 'one');

        $this->assertSame(
            ['App', 'Store', 'Store URL', 'Email', 'Plan', 'Shopify Plan', 'Status', 'Installs', 'Installed'],
            $this->export()[0]
        );
    }

    public function test_a_legacy_plan_stored_as_a_bare_string_still_names_the_plan(): void
    {
        $install = $this->install($this->appA, 'legacy');

        // Written past the model: the array cast would json_encode the value
        // a second time, which is not the shape these older rows are in.
        DB::table('installations')->where('id', $install->id)->update(['app_plan' => '"Premium"']);

        $this->assertSame('Premium', $this->export()[1][4]);
    }

    public function test_it_needs_the_export_permission(): void
    {
        $viewer = User::factory()->create();
        $role = Role::create(['name' => 'Viewer only', 'slug' => 'viewer-only']);
        $role->permissions()->attach(Permission::firstWhere('slug', 'installations.view'));
        $viewer->roles()->attach($role);

        $this->actingAs($viewer)->get('/api/installations/export')->assertForbidden();
    }

    public function test_it_is_closed_to_anonymous_callers(): void
    {
        $this->getJson('/api/installations/export')->assertUnauthorized();
    }

    public function test_a_malformed_date_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/installations/export?date_from=not-a-date')
            ->assertStatus(422);
    }
}
