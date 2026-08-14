<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\Installation;
use App\Models\App;
use App\Models\Integration;
use App\Mail\TemplateMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailTemplateService
{
    /**
     * Send email based on template type for an installation
     *
     * @param Installation $installation
     * @param string $templateType
     * @param array $additionalVariables
     * @return bool
     */
    public function sendTemplateEmail(Installation $installation, string $templateType, array $additionalVariables = []): bool
    {
        try {
            // Find the active template for this app and type
            $template = EmailTemplate::where('app_id', $installation->app_id)
                ->where('type', $templateType)
                ->where('is_active', true)
                ->first();

            if (!$template) {
                Log::warning("No active template found for type: {$templateType}, app_id: {$installation->app_id}");
                return false;
            }

            // Prepare variables for template rendering
            $variables = $this->prepareVariables($installation, $additionalVariables);

            // Render the template
            $rendered = $template->render($variables);

            $mailable = new TemplateMail($rendered['subject'], $rendered['body']);
            $sendgrid = $this->resolveSendgrid();
            $mailerName = $sendgrid ? 'sendgrid_dynamic' : null;

            if ($sendgrid) {
                $mailable->from($sendgrid['from_email'], $sendgrid['from_name'] ?? null);

                if (!empty($sendgrid['reply_to'])) {
                    $mailable->replyTo($sendgrid['reply_to']);
                }
            }

            $pending = $mailerName ? Mail::mailer($mailerName) : Mail::mailer(config('mail.default'));
            $pending = $pending->to($installation->email);

            if (!empty($sendgrid['cc'])) {
                $pending->cc($this->parseAddressList($sendgrid['cc']));
            }

            if (!empty($sendgrid['bcc'])) {
                $pending->bcc($this->parseAddressList($sendgrid['bcc']));
            }

            $pending->send($mailable);

            Log::info("Email sent successfully", [
                'type' => $templateType,
                'recipient' => $installation->email,
                'installation_id' => $installation->id,
                'via' => $mailerName ?? 'default',
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to send email", [
                'error' => $e->getMessage(),
                'type' => $templateType,
                'installation_id' => $installation->id,
            ]);

            return false;
        }
    }

    /**
     * If SendGrid is enabled and fully configured, register its SMTP mailer
     * and return its config; otherwise return null so the caller falls back
     * to the default mailer configured via .env.
     *
     * @return array|null
     */
    protected function resolveSendgrid(): ?array
    {
        $integration = Integration::findByKey('sendgrid');

        if (!$integration || !$integration->is_enabled) {
            return null;
        }

        $config = $integration->config ?? [];

        if (empty($config['api_key']) || empty($config['from_email'])) {
            Log::warning('SendGrid integration enabled but api_key or from_email is missing; falling back to default mailer');
            return null;
        }

        config(['mail.mailers.sendgrid_dynamic' => [
            'transport' => 'smtp',
            'host' => 'smtp.sendgrid.net',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'apikey',
            'password' => $config['api_key'],
        ]]);

        return $config;
    }

    /**
     * Split a comma-separated address string into an array.
     *
     * @param string $addresses
     * @return array
     */
    protected function parseAddressList(string $addresses): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $addresses))));
    }

    /**
     * Prepare variables for template rendering
     *
     * @param Installation $installation
     * @param array $additionalVariables
     * @return array
     */
    protected function prepareVariables(Installation $installation, array $additionalVariables = []): array
    {
        $app = $installation->app;

        $variables = [
            'customer_name' => $installation->shop_owner_name ?? 'Valued Customer',
            'customer_email' => $installation->email,
            'store_name' => $installation->store_name,
            'store_url' => $installation->store_url,
            'app_name' => $app->app_name ?? 'Our App',
            'installation_date' => $installation->created_at->format('M d, Y'),
            'uninstallation_date' => now()->format('M d, Y'),
            'shopify_plan' => $installation->shopify_plan ?? 'N/A',
            'currency' => $installation->currency ?? 'USD',
        ];

        // Merge with additional variables
        return array_merge($variables, $additionalVariables);
    }

    /**
     * Send installation email
     *
     * @param Installation $installation
     * @return bool
     */
    public function sendInstallationEmail(Installation $installation): bool
    {
        return $this->sendTemplateEmail($installation, 'install');
    }

    /**
     * Send uninstallation email
     *
     * @param Installation $installation
     * @return bool
     */
    public function sendUninstallationEmail(Installation $installation): bool
    {
        return $this->sendTemplateEmail($installation, 'uninstall');
    }

    /**
     * Send 7-day follow-up email
     *
     * @param Installation $installation
     * @return bool
     */
    public function send7DayFollowupEmail(Installation $installation): bool
    {
        $daysActive = $installation->created_at->diffInDays(now());
        
        return $this->sendTemplateEmail($installation, '7_day_followup', [
            'days_active' => $daysActive,
        ]);
    }
}
