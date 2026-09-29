<?php

namespace App\Listeners;

use App\Events\FeatureRequestStatusChanged;
use App\Jobs\SendBoardTeamEmail;
use App\Models\FeatureBoard;

/**
 * Tells the team when a merchant files a request.
 *
 * Rides the same event as the status emails because a submission already logs
 * a transition into Pending — it is the only one with nothing before it, which
 * is how a submission is told apart from a move.
 */
class NotifyTeamOfNewFeatureRequest
{
    public function handle(FeatureRequestStatusChanged $event): void
    {
        if ($event->log->from_status !== null) {
            return;
        }

        $request = $event->featureRequest;
        $app = $request->app;
        $recipient = $app?->board?->newRequestRecipient();

        if (! $recipient) {
            return;
        }

        SendBoardTeamEmail::dispatch(
            (int) $request->app_id,
            $recipient,
            FeatureBoard::NEW_REQUEST_TEMPLATE,
            [
                'app_name' => $app->app_name ?? '',
                'store_name' => $request->installation?->store_name
                    ?? (string) $request->submitter_shop_domain,
                'request_title' => $request->title,
                'request_description' => (string) $request->description,
                'board_url' => $app->board_slug
                    ? rtrim((string) config('board.url'), '/') . '/board/' . $app->board_slug
                    : '',
                'admin_url' => rtrim((string) config('board.url'), '/') . '/feature-requests',
            ],
        );
    }
}
