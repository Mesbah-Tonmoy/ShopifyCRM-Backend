<?php

namespace App\Console\Commands;

use App\Models\EmailTemplate;
use App\Models\Installation;
use App\Services\EmailTemplateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sends the follow-up email to stores that installed an app seven days ago,
 * for every app whose follow-up template is active. Scheduled hourly, so a
 * store hears from us within the hour its seventh day begins.
 */
class SendFollowupEmailsCommand extends Command
{
    /**
     * Days after installation the follow-up goes out.
     */
    public const AFTER_DAYS = 7;

    /**
     * How long past day seven a store is still caught up on, so a scheduler
     * outage does not silently skip anyone. Bounded so that switching the
     * template on never mails every store that installed months ago.
     */
    public const GRACE_DAYS = 2;

    protected $signature = 'emails:send-followups
                            {--dry-run : List the stores that would be emailed without sending}';

    protected $description = 'Send the 7-day follow-up email to stores installed seven days ago';

    public function handle(EmailTemplateService $emails): int
    {
        $appIds = EmailTemplate::active()
            ->byType(EmailTemplateService::FOLLOWUP_TYPE)
            ->pluck('app_id');

        if ($appIds->isEmpty()) {
            $this->info('No app has an active follow-up template; nothing to send.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        $this->eligible($appIds->all())->chunkById(100, function ($installations) use ($emails, &$sent, &$failed) {
            foreach ($installations as $installation) {
                if ($this->option('dry-run')) {
                    $this->line("Would email {$installation->email} ({$installation->store_url})");
                    $sent++;

                    continue;
                }

                if ($emails->send7DayFollowupEmail($installation)) {
                    $installation->update(['followup_email_sent_at' => now()]);
                    $sent++;
                } else {
                    // The service already logged why on the `emails` channel.
                    $failed++;
                }
            }
        });

        $summary = ['sent' => $sent, 'failed' => $failed, 'dry_run' => (bool) $this->option('dry-run')];

        Log::channel('emails')->info('Follow-up email run finished', $summary);
        $this->info(($this->option('dry-run') ? 'Would send' : 'Sent') . " {$sent} follow-up email(s), {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Active stores of these apps whose seventh day has begun within the grace
     * window and that have not had the follow-up yet.
     *
     * @param  array<int, int>  $appIds
     */
    private function eligible(array $appIds)
    {
        return Installation::with('app')
            ->whereIn('app_id', $appIds)
            ->where('is_active', true)
            ->whereNull('followup_email_sent_at')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            // installed_at is the merchant's real install date; created_at is
            // only when the row reached the CRM, which for imported stores is
            // months later.
            ->whereRaw('COALESCE(installed_at, created_at) <= ?', [now()->subDays(self::AFTER_DAYS)])
            ->whereRaw('COALESCE(installed_at, created_at) > ?', [now()->subDays(self::AFTER_DAYS + self::GRACE_DAYS)]);
    }
}
