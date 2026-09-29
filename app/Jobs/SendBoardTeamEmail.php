<?php

namespace App\Jobs;

use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Sends one board email to a fixed address rather than to a store.
 *
 * The sibling of SendFeatureRequestEmail: same retry behaviour, but the
 * recipient is whoever the board names, who need not be an installed store —
 * so there is no Installation to carry variables, and the caller supplies them.
 */
class SendBoardTeamEmail implements ShouldQueue
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
        public readonly int $appId,
        public readonly string $recipient,
        public readonly string $templateType,
        public readonly array $variables = [],
    ) {
    }

    public function handle(EmailTemplateService $emails): void
    {
        $sent = $emails->sendTemplate(
            $this->appId,
            $this->recipient,
            $this->templateType,
            $this->variables,
        );

        // As with the store-facing job: the mail service reports failure by
        // returning false, so throwing is what puts a failed send in front of
        // someone instead of losing it.
        if (! $sent) {
            throw new RuntimeException(sprintf(
                'Could not send "%s" to %s.',
                $this->templateType,
                $this->recipient,
            ));
        }
    }
}
