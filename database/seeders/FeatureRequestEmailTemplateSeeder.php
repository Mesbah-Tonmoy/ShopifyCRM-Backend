<?php

namespace Database\Seeders;

use App\Enums\FeatureRequestStatus;
use App\Models\App;
use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

/**
 * Default wording for the board's status emails.
 *
 * Types come from FeatureRequestStatus::templateType(), so the enum stays the
 * single source of truth and a renamed status cannot leave an orphaned
 * template behind.
 */
class FeatureRequestEmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        App::all()->each(fn (App $app) => static::seedTemplatesForApp($app));
    }

    public static function seedTemplatesForApp(App $app): void
    {
        foreach (static::templates() as $status => $template) {
            EmailTemplate::updateOrCreate(
                [
                    'app_id' => $app->id,
                    'type' => FeatureRequestStatus::from($status)->templateType(),
                ],
                [
                    'app_id' => $app->id,
                    'type' => FeatureRequestStatus::from($status)->templateType(),
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
            FeatureRequestStatus::Pending->value => [
                'subject' => 'We got your request: {{request_title}}',
                'body' => "Hi {{store_name}},\n\n"
                    . "Thanks for suggesting \"{{request_title}}\" for {{app_name}}.\n\n"
                    . "It's on the board now, where other stores can add their votes. "
                    . "The more support it gets, the sooner we look at it.\n\n"
                    . "See it here: {{board_url}}\n\n"
                    . "Thanks for helping shape {{app_name}}.\n\n"
                    . "The {{app_name}} team",
            ],
            FeatureRequestStatus::Approved->value => [
                'subject' => "We're taking on: {{request_title}}",
                'body' => "Hi {{store_name}},\n\n"
                    . "Good news — \"{{request_title}}\" has been approved and is queued for a future release.\n\n"
                    . "{{status_note}}\n\n"
                    . "Follow it here: {{board_url}}\n\n"
                    . "The {{app_name}} team",
            ],
            FeatureRequestStatus::InProgress->value => [
                'subject' => "We've started building: {{request_title}}",
                'body' => "Hi {{store_name}},\n\n"
                    . "You asked for \"{{request_title}}\" — we're building it now.\n\n"
                    . "{{status_note}}\n\n"
                    . "{{votes_count}} stores are behind this one. We'll email you the moment it ships.\n\n"
                    . "Track it here: {{board_url}}\n\n"
                    . "The {{app_name}} team",
            ],
            FeatureRequestStatus::Completed->value => [
                'subject' => "It's live: {{request_title}}",
                'body' => "Hi {{store_name}},\n\n"
                    . "\"{{request_title}}\" has shipped and is available in {{app_name}} now.\n\n"
                    . "{{status_note}}\n\n"
                    . "Thanks for asking for it — requests like yours decide what we build next.\n\n"
                    . "See what else is coming: {{board_url}}\n\n"
                    . "The {{app_name}} team",
            ],
            FeatureRequestStatus::Rejected->value => [
                'subject' => 'About your request: {{request_title}}',
                'body' => "Hi {{store_name}},\n\n"
                    . "We've looked at \"{{request_title}}\" and won't be building it for now.\n\n"
                    . "{{status_note}}\n\n"
                    . "We'd rather tell you straight than leave it sitting open. "
                    . "If your situation is different from what we assumed, reply and tell us.\n\n"
                    . "The board is here if you'd like to back something else: {{board_url}}\n\n"
                    . "The {{app_name}} team",
            ],
        ];
    }
}
