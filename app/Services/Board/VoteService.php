<?php

namespace App\Services\Board;

use App\Models\FeatureRequest;
use App\Models\FeatureRequestVote;
use App\Support\Board\BoardIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Adds and removes votes, keeping the denormalised `votes_count` in step with
 * the votes table inside a single transaction.
 *
 * The unique index on (feature_request_id, voter_key) is the real guarantee of
 * one vote per store; this service just keeps the counter honest.
 */
class VoteService
{
    /**
     * Record a vote. Idempotent: voting twice from the same store is a no-op.
     */
    public function add(FeatureRequest $request, BoardIdentity $identity, ?string $ip = null): FeatureRequest
    {
        DB::transaction(function () use ($request, $identity, $ip) {
            try {
                $vote = FeatureRequestVote::firstOrCreate(
                    [
                        'feature_request_id' => $request->id,
                        'voter_key' => $identity->voterKey,
                    ],
                    [
                        'app_id' => $request->app_id,
                        'installation_id' => $identity->installationId,
                        'ip_address' => $ip,
                    ]
                );
            } catch (QueryException $e) {
                // Two concurrent votes from the same store; the unique index
                // won the race, which is exactly the outcome we want.
                return;
            }

            if ($vote->wasRecentlyCreated) {
                $request->increment('votes_count');
            }
        });

        return $this->syncVoteCount($request);
    }

    /**
     * Withdraw a vote. Idempotent in the same way.
     */
    public function remove(FeatureRequest $request, BoardIdentity $identity): FeatureRequest
    {
        DB::transaction(function () use ($request, $identity) {
            $deleted = FeatureRequestVote::where('feature_request_id', $request->id)
                ->where('voter_key', $identity->voterKey)
                ->delete();

            if ($deleted > 0) {
                // Guard against ever going negative if a counter has drifted.
                $request->newQuery()
                    ->whereKey($request->id)
                    ->where('votes_count', '>', 0)
                    ->decrement('votes_count', $deleted);
            }
        });

        return $this->syncVoteCount($request);
    }

    /**
     * Refresh just the vote tally.
     *
     * A full refresh() would re-query the row without any withCount aggregates
     * the caller loaded, so counts such as `comments_count` would come back as
     * null and wipe the value the client already had.
     */
    protected function syncVoteCount(FeatureRequest $request): FeatureRequest
    {
        $request->setAttribute(
            'votes_count',
            (int) $request->newQuery()->whereKey($request->getKey())->value('votes_count')
        );

        return $request;
    }

    /**
     * Whether this store has already voted for the request.
     */
    public function hasVoted(FeatureRequest $request, BoardIdentity $identity): bool
    {
        return $request->votes()->where('voter_key', $identity->voterKey)->exists();
    }

    /**
     * How many requests this store has backed, for its activity card.
     */
    public function countVotesBy(BoardIdentity $identity): int
    {
        return FeatureRequestVote::where('app_id', $identity->app->id)
            ->where('voter_key', $identity->voterKey)
            ->count();
    }
}
