<?php

namespace App\Http\Resources\Board;

use App\Models\FeatureBoard;
use App\Models\FeatureRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a feature request.
 *
 * Deliberately omits everything internal: the submitter's shop domain and
 * email, admin notes, and moderation flags never reach the merchant board.
 *
 * @mixin FeatureRequest
 */
class FeatureRequestResource extends JsonResource
{
    /**
     * Board whose display settings apply. Set fluently rather than through the
     * constructor, which JsonResource::collection() calls with the collection
     * key as its second argument.
     */
    protected ?FeatureBoard $board = null;

    public function forBoard(?FeatureBoard $board): static
    {
        $this->board = $board;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_note' => $this->status_note,
            'votes_count' => $this->showsVoteCounts() ? $this->votes_count : null,
            'has_voted' => (bool) ($this->has_voted ?? false),
            'is_subscribed' => (bool) ($this->is_subscribed ?? false),
            'is_pinned' => $this->is_pinned,
            'comments_count' => $this->comments_count ?? 0,
            'submitter_name' => $this->relationLoaded('installation') ? $this->installation?->store_name : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Boards can hide vote tallies while still accepting votes.
     */
    protected function showsVoteCounts(): bool
    {
        return $this->board?->show_vote_counts ?? true;
    }

    /**
     * Build a paginator whose items are already in public shape, keeping the
     * envelope identical to the rest of the API.
     */
    public static function paginate($paginator, ?FeatureBoard $board = null)
    {
        return $paginator->through(fn (FeatureRequest $request) => static::make($request)->forBoard($board)->resolve());
    }

    /**
     * @param  iterable<FeatureRequest>  $requests
     * @return array<int, array<string, mixed>>
     */
    public static function collectPlain(iterable $requests, ?FeatureBoard $board = null): array
    {
        $out = [];

        foreach ($requests as $request) {
            $out[] = static::make($request)->forBoard($board)->resolve();
        }

        return $out;
    }
}
