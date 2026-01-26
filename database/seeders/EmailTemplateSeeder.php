<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Models\App;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // This seeder can be called for all apps or a specific app
        // If called without arguments, it will seed templates for all apps
        $apps = App::all();

        foreach ($apps as $app) {
            $this->seedTemplatesForApp($app);
        }
    }

    /**
     * Seed default email templates for a specific app.
     *
     * @param App $app
     * @return void
     */
    public static function seedTemplatesForApp(App $app): void
    {
        $templates = [
            [
                'app_id' => $app->id,
                'type' => 'install',
                'subject' => 'Welcome to {{app_name}}! 🎉',
                'body' => 'Hi {{customer_name}},

                        Thank you for installing {{app_name}}!

                        We\'re excited to have you on board. Here\'s what you can do next:

                        1. Complete your profile setup
                        2. Explore our features and tools
                        3. Check out our documentation for helpful guides

                        If you have any questions or need assistance, our support team is here to help.

                        Best regards,
                        The {{app_name}} Team

                        ---
                        Store: {{store_name}}
                        Email: {{email}}
                        Installation Date: {{installation_date}}',

                'is_active' => false,
            ],
            [
                'app_id' => $app->id,
                'type' => 'uninstall',
                'subject' => 'Sorry to see you go - {{app_name}}',
                'body' => 'Hi {{customer_name}},

                        We noticed that you\'ve uninstalled {{app_name}} from your store {{store_name}}.

                        We\'re sorry to see you go! We\'d love to know what went wrong so we can improve our app.

                        Would you mind taking a moment to share your feedback?

                        If you uninstalled by mistake or would like to give us another try, you can reinstall {{app_name}} anytime.

                        We hope to see you again soon!

                        Best regards,
                        The {{app_name}} Team

                        ---
                        Store: {{store_name}}
                        Email: {{email}}
                        Uninstallation Date: {{uninstallation_date}}',

                'is_active' => false,
            ],
            [
                'app_id' => $app->id,
                'type' => '7_day_followup',
                'subject' => 'How\'s your experience with {{app_name}}? 💬',
                'body' => 'Hi {{customer_name}},

                        It\'s been 7 days since you installed {{app_name}}, and we wanted to check in!

                        How has your experience been so far? We\'d love to hear your feedback.

                        Here are some tips to get the most out of {{app_name}}:

                        ✓ Explore our advanced features in the dashboard
                        ✓ Set up automation to save time
                        ✓ Check out our tutorials and guides
                        ✓ Connect with our community for tips and tricks

                        Need help with anything? Our support team is just an email away.

                        Thank you for choosing {{app_name}}!

                        Best regards,
                        The {{app_name}} Team

                        ---
                        Store: {{store_name}}
                        Email: {{email}}
                        Days Active: 7',

                'is_active' => false,
            ],
        ];

        foreach ($templates as $template) {
            EmailTemplate::updateOrCreate(
                [
                    'app_id' => $template['app_id'],
                    'type' => $template['type'],
                ],
                $template
            );
        }
    }
}
