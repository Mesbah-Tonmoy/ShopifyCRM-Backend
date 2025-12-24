<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\Installation;
use App\Models\App;
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

            // Send the email
            Mail::to($installation->email)->send(
                new TemplateMail($rendered['subject'], $rendered['body'])
            );

            Log::info("Email sent successfully", [
                'type' => $templateType,
                'recipient' => $installation->email,
                'installation_id' => $installation->id,
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
