<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\Installation;
use App\Models\App;
use App\Mail\TemplateMail;
use App\Support\AddressList;
use App\Services\Mail\MailProviderRegistry;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailTemplateService
{
    /**
     * Log channel for everything email related.
     */
    private const LOG = 'emails';

    /**
     * Template type of the email sent seven days after installation.
     */
    public const FOLLOWUP_TYPE = '7_day_followup';

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
            // Installation-shaped fields for the log lines. sendTemplate() knows
            // only about an app and an address, so anything that identifies the
            // store has to be handed down from here.
            [
                'installation_id' => $installation->id,
                'app_id' => $installation->app_id,
                'store_url' => $installation->store_url,
            ],
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
        string|array $recipient,
        string $templateType,
        array $variables = [],
        array $context = [],
        string|array|null $cc = null,
        string|array|null $bcc = null,
    ): bool {
        $to = AddressList::parse($recipient);

        $logContext = array_merge(['type' => $templateType, 'recipient' => implode(', ', $to)], $context);

        // Guarded here rather than in sendTemplateEmail() so both entry points
        // are covered: a board's notification address can be blank too. A cc or
        // bcc on its own is not enough - a message still needs a To.
        if ($to === []) {
            Log::channel(self::LOG)->error('Email not sent: no recipient address', $logContext);

            return false;
        }

        $startedAt = microtime(true);

        Log::channel(self::LOG)->info('Sending email', $logContext);

        try {
            // Find the active template for this app and type
            $template = EmailTemplate::where('app_id', $appId)
                ->where('type', $templateType)
                ->where('is_active', true)
                ->first();

            if (!$template) {
                Log::channel(self::LOG)->warning('Email not sent: no active template for this type', $logContext);

                return false;
            }

            $logContext['template_id'] = $template->id;

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
            $pending = $pending->to($to);

            // The provider's own cc/bcc apply to everything it sends; the
            // caller's are for this message alone. Both go on, de-duplicated.
            $ccList = AddressList::parse(array_merge(
                AddressList::parse($provider['cc'] ?? null),
                AddressList::parse($cc),
            ));

            $bccList = AddressList::parse(array_merge(
                AddressList::parse($provider['bcc'] ?? null),
                AddressList::parse($bcc),
            ));

            if ($ccList !== []) {
                $pending->cc($ccList);
            }

            if ($bccList !== []) {
                $pending->bcc($bccList);
            }

            // Folded into the array the log calls below actually read. Built
            // before the send so that a throw still reports what was attempted.
            $logContext += [
                'via' => $providerLabel,
                'subject' => $rendered['subject'],
                'from' => $provider['from_email'] ?? config('mail.from.address'),
                'cc' => $ccList ? implode(', ', $ccList) : null,
                'bcc' => $bccList ? implode(', ', $bccList) : null,
                'ses_tenant' => $resolved?->tenant,
            ];

            $pending->send($mailable);

            Log::channel(self::LOG)->info('Email sent successfully', $logContext + [
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);

            return true;

        } catch (\Throwable $e) {
            // Throwable, not Exception: a TypeError in the mail stack is a
            // failed send like any other, and should be reported as one rather
            // than taking the queue worker down with it.
            Log::channel(self::LOG)->error('Failed to send email', $logContext + [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'at' => $e->getFile() . ':' . $e->getLine(),
                'via' => $providerLabel ?? 'unresolved (failed before mailer selection)',
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
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
            // The seeded templates use {{email}}; without this it was sent
            // to merchants as the literal placeholder.
            'email' => $installation->email,
            'store_name' => $installation->store_name,
            'store_url' => $installation->store_url,
            'app_name' => $app->app_name ?? 'Our App',
            'installation_date' => $this->installedAt($installation)->format('M d, Y'),
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
        $daysActive = (int) floor($this->installedAt($installation)->diffInDays(now(), true));

        return $this->sendTemplateEmail($installation, self::FOLLOWUP_TYPE, [
            'days_active' => $daysActive,
        ]);
    }

    /**
     * When the merchant installed the app. created_at is only when the row
     * reached the CRM, which for imported stores is months later.
     */
    protected function installedAt(Installation $installation): \Carbon\CarbonInterface
    {
        return $installation->installed_at ?? $installation->created_at;
    }
}
