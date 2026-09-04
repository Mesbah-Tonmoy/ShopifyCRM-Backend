<?php

namespace App\Listeners;

use App\Enums\FeatureRequestStatus;
use App\Events\FeatureRequestStatusChanged;
use App\Jobs\SendFeatureRequestEmail;
use App\Models\FeatureRequest;
use App\Models\Installation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

/**
 * Turns a status change into emails.
 *
 * Kept out of the service that performs the move, so moderation logic stays
 * free of mail concerns.
 */
class SendFeatureRequestStatusNotification implements ShouldQueue
{
    public function handle(FeatureRequestStatusChanged $event): void
    {
        $request = $event->featureRequest;
        $log = $event->log;
        $status = $log->to_status;

        // The admin unticked "notify" for this particular move.
        if (! $event->notify) {
            return;
        }

        // Already sent for this transition. Guards against a retried job or a
        // status flipped back and forth mailing everyone twice.
        if ($log->wasNotified()) {
            return;
        }

        $board = $request->app?->board;

        if (! $board || ! $board->notify_on_status_change) {
            return;
        }

        $recipients = $this->recipients($request, $status);

        if ($recipients->isEmpty()) {
            return;
        }

        $variables = $this->variables($request, $status, $log->note);

        foreach ($recipients as $installation) {
            SendFeatureRequestEmail::dispatch($installation, $status->templateType(), $variables);
        }

        $log->forceFill(['notified_at' => now()])->save();
    }

    /**
     * Who hears about this move.
     *
     * The submitter always does, and so does anyone who explicitly subscribed.
     * Everyone who merely voted hears only about the stages they actually care
     * about — being built, and shipped.
     *
     * @return Collection<int, Installation>
     */
    protected function recipients(FeatureRequest $request, FeatureRequestStatus $status): Collection
    {
        $installations = collect();

        if ($request->installation && filled($request->installation->email)) {
            $installations->put($request->installation->id, $request->installation);
        }

        // Stores that explicitly asked to follow this one hear about every
        // stage, not just the two that voters are told about.
        $request->subscribers()
            ->with('installation')
            ->whereNotNull('installation_id')
            ->chunkById(200, function (Collection $subscribers) use ($installations) {
                foreach ($subscribers as $subscriber) {
                    $installation = $subscriber->installation;

                    if ($installation && filled($installation->email)) {
                        $installations->put($installation->id, $installation);
                    }
                }
            });

        if (! $status->notifiesVoters()) {
            return $installations->values();
        }

        $request->votes()
            ->with('installation')
            ->whereNotNull('installation_id')
            ->chunkById(200, function (Collection $votes) use ($installations) {
                foreach ($votes as $vote) {
                    $installation = $vote->installation;

                    // Stores that voted but have no installation record cannot
                    // be emailed; their vote still counts on the board.
                    if ($installation && filled($installation->email)) {
                        $installations->put($installation->id, $installation);
                    }
                }
            });

        return $installations->values();
    }

    /**
     * @return array<string, mixed>
     */
    protected function variables(FeatureRequest $request, FeatureRequestStatus $status, ?string $note): array
    {
        $app = $request->app;

        return [
            'request_title' => $request->title,
            'request_description' => (string) $request->description,
            'request_status' => $status->label(),
            'status_note' => (string) ($note ?? $request->status_note),
            'votes_count' => (string) $request->votes_count,
            'board_url' => $app?->board_slug
                ? rtrim((string) config('board.url'), '/') . '/board/' . $app->board_slug
                : '',
        ];
    }
}
