<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\Installation;
use App\Models\App;
use App\Mail\TemplateMail;
use App\Services\Mail\MailProviderRegistry;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailTemplateService
{
    protected MailProviderRegistry $mailProviders;

    public function __construct(?MailProviderRegistry $mailProviders = null)
    {
        $this->mailProviders = $mailProviders ?? app(MailProviderRegistry::class);
    }

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
        return $this->sendTemplate(
            (int) $installation->app_id,
            (string) $installation->email,
            $templateType,
            $this->prepareVariables($installation, $additionalVariables),
            ['installation_id' => $installation->id],
        );
    }

    /**
     * Send a template to any address on behalf of an app.
     *
     * Split out of sendTemplateEmail() because not every board email goes to a
     * store: the heads-up about a new request goes to whoever the board names,
     * who may have no installation record at all. The installation-shaped
     * variables are the caller's job, so this knows only about an app, an
     * address and a template.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $context  Extra fields for the log lines.
     */
    public function sendTemplate(
        int $appId,
        string $recipient,
        string $templateType,
        array $variables = [],
        array $context = [],
    ): bool {
        $logContext = array_merge(['type' => $templateType, 'recipient' => $recipient], $context);

        if (blank($recipient)) {
            Log::warning('No recipient for template email', $logContext);

            return false;
        }

        try {
            // Find the active template for this app and type
            $template = EmailTemplate::where('app_id', $appId)
                ->where('type', $templateType)
                ->where('is_active', true)
                ->first();

            if (!$template) {
                Log::warning("No active template found for type: {$templateType}, app_id: {$appId}");
                return false;
            }

            // Render the template
            $rendered = $template->render($variables);

            $mailable = new TemplateMail($rendered['subject'], $rendered['body']);
            $resolved = $this->mailProviders->resolveActive();
            $provider = $resolved?->config;
            $mailerName = $resolved?->mailer;
            $providerLabel = $mailerName ?? 'default (' . config('mail.default') . ')';

            if ($provider) {
                $mailable->from($provider['from_email'], $provider['from_name'] ?? null);

                if (!empty($provider['reply_to'])) {
                    $mailable->replyTo($provider['reply_to']);
                }
            }

            if ($resolved?->headers) {
                $headers = $resolved->headers;
                $mailable->withSymfonyMessage(function ($message) use ($headers) {
                    foreach ($headers as $name => $value) {
                        $message->getHeaders()->addTextHeader($name, $value);
                    }
                });
            }

            $pending = $mailerName ? Mail::mailer($mailerName) : Mail::mailer(config('mail.default'));
            $pending = $pending->to($recipient);

            if (!empty($provider['cc'])) {
                $pending->cc($this->parseAddressList($provider['cc']));
            }

            if (!empty($provider['bcc'])) {
                $pending->bcc($this->parseAddressList($provider['bcc']));
            }

            $pending->send($mailable);

            Log::info("Email sent successfully", array_merge($logContext, array_filter([
                'template_id' => $template->id,
                'via' => $providerLabel,
                'ses_tenant' => $resolved?->tenant,
            ])));

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to send email", array_merge($logContext, [
                'error' => $e->getMessage(),
                'via' => $providerLabel ?? 'unresolved (failed before mailer selection)',
            ]));

            return false;
        }
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
