<?php

namespace App\Jobs;

use App\Models\Installation;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Sends one board notification to one store.
 *
 * Deliberately one job per recipient: a status change on a popular request can
 * fan out to dozens of stores, and a single failure part way through must not
 * re-send to everyone already notified when the job retries.
 */
class SendFeatureRequestEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public readonly Installation $installation,
        public readonly string $templateType,
        public readonly array $variables = [],
    ) {
    }

    public function handle(EmailTemplateService $emails): void
    {
        if (blank($this->installation->email)) {
            return;
        }

        $sent = $emails->sendTemplateEmail($this->installation, $this->templateType, $this->variables);

        // The mail service catches its own exceptions and reports false, so a
        // failed send would otherwise disappear silently. Throwing hands it
        // back to the queue, which retries and finally records it in
        // failed_jobs where it can be seen.
        if (! $sent) {
            throw new RuntimeException(sprintf(
                'Could not send "%s" to %s.',
                $this->templateType,
                $this->installation->email,
            ));
        }
    }
}
