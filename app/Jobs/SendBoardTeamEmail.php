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
 * recipients are whoever the board names, who need not be installed stores —
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
     * @param  string|array<int, string>  $recipient
     * @param  array<string, mixed>  $variables
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     */
    public function __construct(
        public readonly int $appId,
        public readonly string|array $recipient,
        public readonly string $templateType,
        public readonly array $variables = [],
        public readonly array $cc = [],
        public readonly array $bcc = [],
    ) {
    }

    public function handle(EmailTemplateService $emails): void
    {
        $sent = $emails->sendTemplate(
            $this->appId,
            $this->recipient,
            $this->templateType,
            $this->variables,
            cc: $this->cc,
            bcc: $this->bcc,
        );

        // As with the store-facing job: the mail service reports failure by
        // returning false, so throwing is what puts a failed send in front of
        // someone instead of losing it.
        if (! $sent) {
            throw new RuntimeException(sprintf(
                'Could not send "%s" to %s.',
                $this->templateType,
                implode(', ', (array) $this->recipient),
            ));
        }
    }
}
