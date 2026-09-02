<?php

namespace Database\Seeders;

use App\Enums\FeatureRequestStatus as Status;
use App\Models\App;
use App\Models\FeatureRequest;
use App\Models\FeatureRequestVote;
use App\Models\Installation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fills a board with believable feature requests, votes and history so the
 * admin kanban and the public board can be reviewed with realistic content.
 *
 * Safe to re-run: it clears the target app's existing requests first.
 * Target a specific app with DEMO_APP_ID, otherwise the first app is used.
 */
class FeatureRequestDemoSeeder extends Seeder
{
    /**
     * Stores that submit and vote. The first four get installation records so
     * the admin voter list shows real Shopify plans.
     *
     * @var array<int, array{0: string, 1: string, 2: string|null}>
     */
    protected array $stores = [
        ['northline-supply', 'Northline Supply', 'Basic'],
        ['halden-co', 'Halden & Co', 'Shopify'],
        ['bloomvale', 'Bloomvale', 'Advanced'],
        ['cedar-post-goods', 'Cedar Post Goods', 'Plus'],
        ['harbor-lane', 'Harbor Lane', null],
        ['atlas-outfitters', 'Atlas Outfitters', null],
        ['verdant-home', 'Verdant Home', null],
        ['quill-and-press', 'Quill & Press', null],
    ];

    /**
     * title, description, status, votes, public note, days old, pinned
     *
     * @var array<int, array{0: string, 1: string, 2: Status, 3: int, 4: string|null, 5: int, 6: bool}>
     */
    protected array $requests = [];

    public function run(): void
    {
        $app = App::when(env('DEMO_APP_ID'), fn ($q) => $q->whereKey(env('DEMO_APP_ID')))->first();

        if (! $app) {
            $this->command->error('No apps found. Connect an app before seeding demo requests.');

            return;
        }

        $app->provisionBoard();

        $existing = FeatureRequest::withTrashed()->forApp($app->id)->count();

        if ($existing > 0) {
            FeatureRequest::withTrashed()->forApp($app->id)->forceDelete();
            $this->command->warn("Cleared {$existing} existing request(s) for {$app->app_name}.");
        }

        $installations = $this->seedInstallations($app);

        DB::transaction(fn () => $this->seedRequests($app, $installations));

        $this->command->info(sprintf(
            'Seeded %d requests and %d votes for %s (board: /board/%s).',
            FeatureRequest::forApp($app->id)->count(),
            FeatureRequestVote::where('app_id', $app->id)->count(),
            $app->app_name,
            $app->board_slug,
        ));
    }

    /**
     * @return array<string, Installation>
     */
    protected function seedInstallations(App $app): array
    {
        $installations = [];

        foreach ($this->stores as $index => [$slug, $name, $plan]) {
            $domain = "{$slug}.myshopify.com";

            // Only the first few stores get an installation record, so the UI
            // also exercises the "no matching installation" path.
            if ($plan === null) {
                continue;
            }

            $installations[$domain] = Installation::updateOrCreate(
                ['app_id' => $app->id, 'store_url' => $domain],
                [
                    'store_name' => $name,
                    'email' => "owner@{$slug}.test",
                    'shopify_plan' => $plan,
                    'currency' => 'USD',
                    'is_active' => $index !== 3, // one uninstalled store, for realism
                    'installed_at' => now()->subDays(120 - $index * 10),
                ]
            );
        }

        return $installations;
    }

    /**
     * @param  array<string, Installation>  $installations
     */
    protected function seedRequests(App $app, array $installations): void
    {
        foreach ($this->requestData() as $index => [$title, $description, $status, $voteCount, $note, $daysOld, $pinned]) {
            [$slug] = $this->stores[$index % count($this->stores)];
            $domain = "{$slug}.myshopify.com";
            $installation = $installations[$domain] ?? null;
            $createdAt = now()->subDays($daysOld);

            $request = FeatureRequest::create([
                'app_id' => $app->id,
                'installation_id' => $installation?->id,
                'submitter_shop_domain' => $domain,
                'submitter_email' => $installation?->email,
                'title' => $title,
                'description' => $description,
                'status' => $status,
                'status_note' => $note,
                'is_visible' => $status !== Status::Pending,
                'is_pinned' => $pinned,
            ]);

            // Timestamps are guarded against mass assignment, so backdate them
            // explicitly — otherwise every card reads as created today.
            $request->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'completed_at' => $status === Status::Completed ? now()->subDays(max(1, $daysOld - 30)) : null,
            ])->save();

            $this->seedHistory($request, $status, $createdAt, $note);
            $this->seedVotes($request, $installations, $voteCount, $createdAt);

            $request->recountVotes();
        }
    }

    protected function seedHistory(FeatureRequest $request, Status $status, $createdAt, ?string $note): void
    {
        $request->statusLogs()->create([
            'from_status' => null,
            'to_status' => Status::Pending,
            'created_at' => $createdAt,
        ]);

        if ($status === Status::Pending) {
            return;
        }

        // Anything past pending went through approval first.
        if (! in_array($status, [Status::Approved, Status::Rejected], true)) {
            $request->statusLogs()->create([
                'from_status' => Status::Pending,
                'to_status' => Status::Approved,
                'created_at' => (clone $createdAt)->addDays(3),
            ]);
        }

        $request->statusLogs()->create([
            'from_status' => $status === Status::Rejected ? Status::Pending : Status::Approved,
            'to_status' => $status,
            'note' => $note,
            'created_at' => (clone $createdAt)->addDays(7),
        ]);
    }

    /**
     * @param  array<string, Installation>  $installations
     */
    protected function seedVotes(FeatureRequest $request, array $installations, int $voteCount, $createdAt): void
    {
        foreach (array_slice($this->stores, 0, $voteCount) as $offset => [$slug]) {
            $domain = "{$slug}.myshopify.com";

            $vote = FeatureRequestVote::create([
                'feature_request_id' => $request->id,
                'app_id' => $request->app_id,
                'installation_id' => $installations[$domain]->id ?? null,
                'voter_key' => $domain,
            ]);

            // Spread votes across the request's lifetime so "trending" is
            // meaningfully different from "most voted".
            $votedAt = (clone $createdAt)->addDays($offset + 1);

            $vote->forceFill([
                'created_at' => $votedAt->isFuture() ? now() : $votedAt,
                'updated_at' => $votedAt->isFuture() ? now() : $votedAt,
            ])->save();
        }
    }

    /**
     * Demo content for a Shopify job-listing app.
     *
     * @return array<int, array{0: string, 1: string, 2: Status, 3: int, 4: string|null, 5: int, 6: bool}>
     */
    protected function requestData(): array
    {
        return [
            [
                'Filter listings by location and remote',
                'Candidates on our careers page have to scroll through every opening. Let them filter by city, country and remote-only so they find relevant roles fast.',
                Status::InProgress, 8, 'In development now — shipping in the next release.', 54, true,
            ],
            [
                'Custom fields on the application form',
                'We need to ask role-specific questions, like portfolio links for designers and a right-to-work declaration. Right now everyone gets the same three fields.',
                Status::Approved, 7, null, 61, false,
            ],
            [
                'Google Jobs structured data for SEO',
                'Add JobPosting schema markup so our openings appear in Google Jobs. Most of our applicants come from search, so this would matter more than anything else on the list.',
                Status::Approved, 6, null, 47, false,
            ],
            [
                'Email alerts for new matching roles',
                'Let candidates subscribe to a filter and get an email when something matching is posted, instead of checking back manually.',
                Status::Pending, 5, null, 12, false,
            ],
            [
                'Auto-expire listings on a set date',
                'Closed roles sit on the careers page until someone remembers to remove them. Let us set a closing date when the listing is created.',
                Status::Pending, 4, null, 9, false,
            ],
            [
                'Applicant pipeline with stages',
                'Track candidates through applied, screening, interview and offer, so we can stop managing this in a spreadsheet alongside the app.',
                Status::Pending, 4, null, 21, false,
            ],
            [
                'Bulk import openings from CSV',
                'We post around forty seasonal roles at once. Adding them one at a time takes most of a morning.',
                Status::Completed, 6, 'Shipped — CSV import is under Listings → Import.', 96, false,
            ],
            [
                'Resume upload with size limits',
                'Applicants attach 20MB scans and the form times out. Let us cap the file size and restrict it to PDF and DOCX.',
                Status::Completed, 5, 'Shipped — configurable in the application form settings.', 88, false,
            ],
            [
                'Salary range with currency formatting',
                'Show a from–to salary range formatted in the store currency. Some regions legally require a range in the posting.',
                Status::Approved, 3, null, 33, false,
            ],
            [
                'Multi-language job descriptions',
                'We hire across Europe and need the same listing in English, German and Dutch, picked up from the storefront locale.',
                Status::Pending, 3, null, 17, false,
            ],
            [
                'Embed listings on a non-Shopify site',
                'Our main marketing site is not on Shopify. A script tag or iframe to surface the same openings there would save duplicating everything.',
                Status::InProgress, 3, 'Being built as an embeddable widget.', 40, false,
            ],
            [
                'Let candidates apply with a LinkedIn profile',
                'Skip the form entirely and pull name, headline and experience from LinkedIn.',
                Status::Rejected, 2, 'LinkedIn closed public profile access to third parties, so we cannot build this reliably.', 70, false,
            ],
        ];
    }
}
