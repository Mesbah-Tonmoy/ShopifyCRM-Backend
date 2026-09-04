<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Board\FeatureRequestResource as BoardResource;
use App\Models\FeatureRequest;
use Illuminate\Http\Request;

/**
 * Admin shape of a feature request: everything the board shows, plus the
 * internal fields the CRM needs for triage.
 *
 * Extends the public resource so a field added for merchants automatically
 * appears for staff, and the two can never drift.
 *
 * @mixin FeatureRequest
 */
class FeatureRequestResource extends BoardResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            // Vote counts are never hidden from staff
            'votes_count' => $this->votes_count,
            'recent_votes_count' => $this->recent_votes_count ?? null,

            // Internal-only
            'submitter_shop_domain' => $this->submitter_shop_domain,
            'submitter_email' => $this->submitter_email,
            'admin_note' => $this->admin_note,
            'is_visible' => $this->is_visible,
            'is_hidden' => $this->is_hidden,
            // What merchants actually see, accounting for the board's
            // moderation setting rather than the raw flag.
            'is_public' => $this->isPubliclyVisible(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'app' => $this->whenLoaded('app', fn () => [
                'id' => $this->app->id,
                'app_name' => $this->app->app_name,
            ]),

            'installation' => $this->whenLoaded('installation', fn () => $this->installation ? [
                'id' => $this->installation->id,
                'store_name' => $this->installation->store_name,
                'store_url' => $this->installation->store_url,
                'email' => $this->installation->email,
                'shopify_plan' => $this->installation->shopify_plan,
                'app_plan' => $this->installation->app_plan,
                'is_active' => $this->installation->is_active,
            ] : null),

            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),

            'status_logs' => $this->whenLoaded('statusLogs', fn () => $this->statusLogs->map(fn ($log) => [
                'id' => $log->id,
                'from_status' => $log->from_status?->value,
                'to_status' => $log->to_status->value,
                'to_status_label' => $log->to_status->label(),
                'note' => $log->note,
                'user' => $log->relationLoaded('user') && $log->user ? $log->user->name : null,
                'notified_at' => $log->notified_at?->toIso8601String(),
                'created_at' => $log->created_at?->toIso8601String(),
            ])->all()),

            'subscribers_count' => $this->subscribers_count ?? null,
            'subscribers' => $this->whenLoaded('subscribers', fn () => $this->subscribers->map(fn ($subscriber) => [
                'id' => $subscriber->id,
                'store_name' => $subscriber->installation?->store_name,
                'subscriber_key' => $subscriber->subscriber_key,
                'subscribed_at' => $subscriber->created_at?->toIso8601String(),
            ])->all()),

            'comments' => $this->whenLoaded('comments', fn () => $this->comments->map(fn ($comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'is_official' => $comment->is_official,
                'is_hidden' => $comment->is_hidden,
                'author_name' => $comment->is_official
                    ? ($comment->user?->name ?? $comment->author_name)
                    : ($comment->installation?->store_name ?? $comment->author_name),
                'author_shop_domain' => $comment->author_shop_domain,
                'created_at' => $comment->created_at?->toIso8601String(),
            ])->all()),

            'voters' => $this->whenLoaded('votes', fn () => $this->votes->map(fn ($vote) => [
                'id' => $vote->id,
                'voter_key' => $vote->voter_key,
                'store_name' => $vote->relationLoaded('installation') && $vote->installation
                    ? $vote->installation->store_name
                    : null,
                'shopify_plan' => $vote->relationLoaded('installation') && $vote->installation
                    ? $vote->installation->shopify_plan
                    : null,
                'app_plan' => $vote->relationLoaded('installation') && $vote->installation
                    ? $vote->installation->app_plan
                    : null,
                'voted_at' => $vote->created_at?->toIso8601String(),
            ])->all()),
        ]);
    }

    /**
     * A board may hide tallies from merchants, but never from staff.
     */
    protected function showsVoteCounts(): bool
    {
        return true;
    }
}
