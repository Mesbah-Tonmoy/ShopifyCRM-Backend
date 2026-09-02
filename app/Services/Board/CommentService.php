<?php

namespace App\Services\Board;

use App\Models\FeatureRequest;
use App\Models\FeatureRequestComment;
use App\Models\User;
use App\Support\Board\BoardIdentity;
use Illuminate\Database\Eloquent\Collection;

/**
 * Creates and reads discussion on a feature request.
 *
 * Merchant comments are attributed to a verified store; team replies are
 * flagged official so the board can badge them.
 */
class CommentService
{
    /**
     * Comments a merchant may read, oldest first so a thread reads top to bottom.
     *
     * @return Collection<int, FeatureRequestComment>
     */
    public function publicThread(FeatureRequest $request): Collection
    {
        return $request->comments()
            ->visible()
            ->with('installation:id,store_name')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Post a comment as a verified store.
     */
    public function addFromBoard(FeatureRequest $request, BoardIdentity $identity, string $body): FeatureRequestComment
    {
        return $request->comments()->create([
            'app_id' => $request->app_id,
            'installation_id' => $identity->installationId,
            'author_shop_domain' => $identity->voterKey,
            'author_name' => $identity->storeName,
            // Set explicitly: the database default is not reflected on the
            // in-memory model, which would serialise as null.
            'is_official' => false,
            'body' => $body,
        ]);
    }

    /**
     * Post an official reply from the team.
     */
    public function addOfficialReply(FeatureRequest $request, User $user, string $body): FeatureRequestComment
    {
        return $request->comments()->create([
            'app_id' => $request->app_id,
            'user_id' => $user->id,
            'author_name' => $user->name,
            'is_official' => true,
            'body' => $body,
        ]);
    }
}
