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
     * Log channel for everything email related.
     */
    private const LOG = 'emails';

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
        $context = [
            'type' => $templateType,
            'installation_id' => $installation->id,
            'app_id' => $installation->app_id,
            'store_url' => $installation->store_url,
            'recipient' => $installation->email,
        ];

        if (empty($installation->email)) {
            Log::channel(self::LOG)->error('Email not sent: installation has no recipient address', $context);

            return false;
        }

        $startedAt = microtime(true);

        Log::channel(self::LOG)->info('Sending email', $context);

        try {
            // Find the active template for this app and type
            $template = EmailTemplate::where('app_id', $installation->app_id)
                ->where('type', $templateType)
                ->where('is_active', true)
                ->first();

            if (!$template) {
                Log::channel(self::LOG)->warning('Email not sent: no active template for this type', $context);

                return false;
            }

            $context['template_id'] = $template->id;

            // Prepare variables for template rendering
            $variables = $this->prepareVariables($installation, $additionalVariables);

            // Render the template
            $rendered = $template->render($variables);

            $mailable = new TemplateMail($rendered['subject'], $rendered['body']);
            $sendgrid = $this->resolveSendgrid();
            $mailtrap = $sendgrid ? null : $this->resolveMailtrap();
            $provider = $sendgrid ?? $mailtrap;
            $mailerName = $sendgrid ? 'sendgrid_dynamic' : ($mailtrap ? 'mailtrap_dynamic' : null);

            if ($provider) {
                $mailable->from($provider['from_email'], $provider['from_name'] ?? null);

                if (!empty($provider['reply_to'])) {
                    $mailable->replyTo($provider['reply_to']);
                }
            }

            $pending = $mailerName ? Mail::mailer($mailerName) : Mail::mailer(config('mail.default'));
            $pending = $pending->to($installation->email);

            $cc = $provider['cc'] ?? null;
            $bcc = $provider['bcc'] ?? null;

            if (!empty($cc)) {
                $pending->cc($this->parseAddressList($cc));
            }

            if (!empty($bcc)) {
                $pending->bcc($this->parseAddressList($bcc));
            }

            $context += [
                'via' => $mailerName ?? config('mail.default'),
                'subject' => $rendered['subject'],
                'from' => $provider['from_email'] ?? config('mail.from.address'),
                'cc' => $cc ?: null,
                'bcc' => $bcc ?: null,
            ];

            $pending->send($mailable);

            Log::channel(self::LOG)->info('Email sent successfully', $context + [
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::channel(self::LOG)->error('Failed to send email', $context + [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'at' => $e->getFile() . ':' . $e->getLine(),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
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
            Log::channel(self::LOG)->warning('SendGrid integration enabled but api_key or from_email is missing; falling back to default mailer');
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
     * If Mailtrap is enabled and fully configured, register its SMTP mailer
     * and return its config; otherwise return null so the caller falls back
     * to the default mailer configured via .env.
     *
     * @return array|null
     */
    protected function resolveMailtrap(): ?array
    {
        $integration = Integration::findByKey('mailtrap');

        if (!$integration || !$integration->is_enabled) {
            return null;
        }

        $config = $integration->config ?? [];

        if (empty($config['username']) || empty($config['password']) || empty($config['from_email'])) {
            Log::channel(self::LOG)->warning('Mailtrap integration enabled but username, password or from_email is missing; falling back to default mailer');
            return null;
        }

        config(['mail.mailers.mailtrap_dynamic' => [
            'transport' => 'smtp',
            'host' => $config['host'] ?? 'live.smtp.mailtrap.io',
            'port' => $config['port'] ?? 587,
            'encryption' => 'tls',
            'username' => $config['username'],
            'password' => $config['password'],
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
