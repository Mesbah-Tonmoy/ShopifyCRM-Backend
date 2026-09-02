<?php

namespace App\Services\Board;

use App\Enums\FeatureRequestStatus;
use App\Events\FeatureRequestStatusChanged;
use App\Models\FeatureBoard;
use App\Models\FeatureRequest;
use App\Models\FeatureRequestStatusLog;
use App\Models\User;
use App\Support\Board\BoardIdentity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Creates feature requests and moves them through their lifecycle.
 *
 * Shared by the public board and the admin API so a status change means the
 * same thing — and writes the same audit trail — whoever triggers it.
 */
class FeatureRequestService
{
    public function __construct(protected VoteService $votes)
    {
    }

    /**
     * Where merchant-supplied screenshots are stored on the public disk.
     */
    public const IMAGE_PATH = 'feature-requests';

    /**
     * Accept a submission from a verified store.
     *
     * @param  array{title: string, description?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function submit(
        FeatureBoard $board,
        BoardIdentity $identity,
        array $data,
        ?UploadedFile $image = null,
    ): FeatureRequest {
        $this->assertSubmissionsAllowed($board, $identity);

        // Store the screenshot before opening the transaction: a rolled back
        // insert should not leave an orphaned file behind.
        $imagePath = $image?->store(self::IMAGE_PATH, 'public') ?: null;

        try {
            return DB::transaction(function () use ($board, $identity, $data, $imagePath) {
                $request = FeatureRequest::create(array_merge($identity->attribution(), [
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'image' => $imagePath,
                    'status' => FeatureRequestStatus::Pending,
                    // Boards without moderation publish submissions immediately.
                    'is_visible' => $board->autoPublishesSubmissions(),
                ]));

                $log = $this->log($request, null, FeatureRequestStatus::Pending, null, null);

                // A merchant who asks for something is backing it by definition.
                $this->votes->add($request, $identity);

                // Same event as any other transition, so the receipt email runs
                // through one notification path rather than a parallel one.
                FeatureRequestStatusChanged::dispatch($request, $log, true);

                return $request->refresh();
            });
        } catch (\Throwable $e) {
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }

            throw $e;
        }
    }

    /**
     * Create a request from the CRM side, on behalf of the team.
     *
     * @param  array<string, mixed>  $data
     */
    public function createByAdmin(array $data, User $user): FeatureRequest
    {
        return DB::transaction(function () use ($data, $user) {
            $status = FeatureRequestStatus::from($data['status'] ?? FeatureRequestStatus::Pending->value);

            $request = FeatureRequest::create(array_merge($data, [
                'status' => $status,
                'created_by_user_id' => $user->id,
                'is_visible' => $data['is_visible'] ?? $status->isPubliclyListed(),
                'completed_at' => $status === FeatureRequestStatus::Completed ? now() : null,
            ]));

            $this->log($request, null, $status, null, $user);

            return $request;
        });
    }

    /**
     * Move a request to a new status, recording who did it and why.
     *
     * Returns the request untouched when the status has not actually changed
     * and no new note was supplied, so re-saving a card cannot spam voters.
     */
    public function changeStatus(
        FeatureRequest $request,
        FeatureRequestStatus $to,
        ?string $note = null,
        ?User $user = null,
        bool $notify = true,
    ): FeatureRequest {
        $from = $request->status;
        $noteChanged = $note !== null && $note !== $request->status_note;

        if ($from === $to && ! $noteChanged) {
            return $request;
        }

        return DB::transaction(function () use ($request, $from, $to, $note, $user, $notify, $noteChanged) {
            $request->forceFill([
                'status' => $to,
                'status_note' => $noteChanged ? $note : $request->status_note,
                // Anything past the pending stage belongs on the public board.
                'is_visible' => $request->is_visible || $to->isPubliclyListed(),
                'completed_at' => $to === FeatureRequestStatus::Completed
                    ? ($request->completed_at ?? now())
                    : null,
            ])->save();

            $log = $this->log($request, $from, $to, $noteChanged ? $note : null, $user);

            if ($from !== $to) {
                FeatureRequestStatusChanged::dispatch($request, $log, $notify);
            }

            return $request->refresh();
        });
    }

    /* -----------------------------------------------------------------
     | Internals
     | -----------------------------------------------------------------
     */

    /**
     * @throws ValidationException
     */
    protected function assertSubmissionsAllowed(FeatureBoard $board, BoardIdentity $identity): void
    {
        if (! $board->allow_submissions) {
            throw ValidationException::withMessages([
                'title' => 'This board is not accepting new requests right now.',
            ]);
        }

        $limit = (int) $board->submission_limit_per_day;

        if ($limit <= 0) {
            return;
        }

        $today = FeatureRequest::forApp($board->app_id)
            ->where('submitter_shop_domain', $identity->voterKey)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($today >= $limit) {
            throw ValidationException::withMessages([
                'title' => "You've reached the limit of {$limit} requests per day. Try again tomorrow.",
            ]);
        }
    }

    protected function log(
        FeatureRequest $request,
        ?FeatureRequestStatus $from,
        FeatureRequestStatus $to,
        ?string $note,
        ?User $user,
    ): FeatureRequestStatusLog {
        return $request->statusLogs()->create([
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'user_id' => $user?->id,
            'created_at' => now(),
        ]);
    }
}
