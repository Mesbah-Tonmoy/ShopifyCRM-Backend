<?php

namespace App\Http\Resources\Board;

use App\Models\FeatureRequestComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a comment. The author's shop domain and email stay internal;
 * merchants see a store name only.
 *
 * @mixin FeatureRequestComment
 */
class CommentResource extends JsonResource
{
    protected string $teamName = 'Team';

    public function forTeam(string $teamName): static
    {
        $this->teamName = $teamName;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'is_official' => $this->is_official,
            'author_name' => $this->displayName($this->teamName),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<FeatureRequestComment>  $comments
     * @return array<int, array<string, mixed>>
     */
    public static function collectPlain(iterable $comments, string $teamName): array
    {
        $out = [];

        foreach ($comments as $comment) {
            $out[] = static::make($comment)->forTeam($teamName)->resolve();
        }

        return $out;
    }
}
