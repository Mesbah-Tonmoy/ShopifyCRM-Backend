<?php

namespace Database\Seeders;

use App\Enums\FeatureRequestStatus;
use App\Models\App;
use App\Models\EmailTemplate;
use App\Models\FeatureBoard;
use Illuminate\Database\Seeder;

/**
 * Default wording for the board's status emails.
 *
 * Status types come from FeatureRequestStatus::templateType(), so the enum
 * stays the single source of truth and a renamed status cannot leave an
 * orphaned template behind. The new-request heads-up belongs to no status, so
 * it is keyed by its own constant on FeatureBoard.
 */
class FeatureRequestEmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        App::all()->each(fn (App $app) => static::seedTemplatesForApp($app));
    }

    public static function seedTemplatesForApp(App $app): void
    {
        foreach (static::templates() as $type => $template) {
            EmailTemplate::updateOrCreate(
                ['app_id' => $app->id, 'type' => $type],
                [
                    'app_id' => $app->id,
                    'type' => $type,
                    'subject' => $template['subject'],
                    'body' => $template['body'],
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * @return array<string, array{subject: string, body: string}>
     */
    protected static function templates(): array
    {
        return [
            FeatureRequestStatus::Pending->templateType() => [
                'subject' => 'We got your request: {{request_title}}',
                'body' => "Hi {{store_name}},\n\n"
                    . "Thanks for suggesting \"{{request_title}}\" for {{app_name}}.\n\n"
                    . "It's on the board now, where other stores can add their votes. "
                    . "The more support it gets, the sooner we look at it.\n\n"
                    . "See it here: {{board_url}}\n\n"
                    . "Thanks for helping shape {{app_name}}.\n\n"
                    . "The {{app_name}} team",
            ],
            FeatureRequestStatus::Approved->templateType() => [
                'subject' => "We're taking on: {{request_title}}",
                'body' => "Hi {{store_name}},\n\n"
                    . "Good news — \"{{request_title}}\" has been approved and is queued for a future release.\n\n"
                    . "{{status_note}}\n\n"
                    . "Follow it here: {{board_url}}\n\n"
                    . "The {{app_name}} team",
            ],
            FeatureRequestStatus::InProgress->templateType() => [
                'subject' => "We've started building: {{request_title}}",
                'body' => "Hi {{store_name}},\n\n"
                    . "You asked for \"{{request_title}}\" — we're building it now.\n\n"
                    . "{{status_note}}\n\n"
                    . "{{votes_count}} stores are behind this one. We'll email you the moment it ships.\n\n"
                    . "Track it here: {{board_url}}\n\n"
                    . "The {{app_name}} team",
            ],
            FeatureRequestStatus::Completed->templateType() => [
                'subject' => "It's live: {{request_title}}",
                'body' => "Hi {{store_name}},\n\n"
                    . "\"{{request_title}}\" has shipped and is available in {{app_name}} now.\n\n"
                    . "{{status_note}}\n\n"
                    . "Thanks for asking for it — requests like yours decide what we build next.\n\n"
                    . "See what else is coming: {{board_url}}\n\n"
                    . "The {{app_name}} team",
            ],
            FeatureRequestStatus::Rejected->templateType() => [
                'subject' => 'About your request: {{request_title}}',
                'body' => "Hi {{store_name}},\n\n"
                    . "We've looked at \"{{request_title}}\" and won't be building it for now.\n\n"
                    . "{{status_note}}\n\n"
                    . "We'd rather tell you straight than leave it sitting open. "
                    . "If your situation is different from what we assumed, reply and tell us.\n\n"
                    . "The board is here if you'd like to back something else: {{board_url}}\n\n"
                    . "The {{app_name}} team",
            ],

            // Addressed to the team, not to a store, so it reads as a work item
            // rather than a thank-you.
            FeatureBoard::NEW_REQUEST_TEMPLATE => [
                'subject' => 'New request for {{app_name}}: {{request_title}}',
                'body' => "{{store_name}} asked for something on the {{app_name}} board.\n\n"
                    . "{{request_title}}\n\n"
                    . "{{request_description}}\n\n"
                    . "Review it here: {{admin_url}}\n"
                    . "Public board: {{board_url}}",
            ],
        ];
    }
}
