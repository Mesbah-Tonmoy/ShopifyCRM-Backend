<?php

namespace App\Models;

use App\Enums\FeatureRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureBoard extends Model
{
    use HasFactory;

    protected $table = 'feature_boards';

    /**
     * Template for the heads-up sent to the team when a request comes in.
     *
     * Not derived from FeatureRequestStatus like the others: this one is
     * addressed to us, not to a store, and fires on submission rather than on
     * entering a status.
     */
    public const NEW_REQUEST_TEMPLATE = 'feature_request_submitted';

    protected $fillable = [
        'app_id',
        'title',
        'intro',
        'theme',
        'is_enabled',
        'allow_submissions',
        'allow_voting',
        'allow_comments',
        'require_approval',
        'show_vote_counts',
        'notify_on_status_change',
        'new_request_email',
        'notify_on_approval',
        'submission_limit_per_day',
        'visible_statuses',
    ];

    protected $casts = [
        'theme' => 'array',
        'visible_statuses' => 'array',
        'is_enabled' => 'boolean',
        'allow_submissions' => 'boolean',
        'allow_voting' => 'boolean',
        'allow_comments' => 'boolean',
        'require_approval' => 'boolean',
        'show_vote_counts' => 'boolean',
        'notify_on_status_change' => 'boolean',
        'notify_on_approval' => 'boolean',
        'submission_limit_per_day' => 'integer',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    /**
     * Board heading, falling back to the app's own name.
     */
    public function getDisplayTitleAttribute(): string
    {
        return $this->title ?: ($this->app?->app_name ?? 'Feature requests');
    }

    /**
     * Roadmap columns to render, as enum cases, ignoring any stale values
     * left in the JSON column after a status was renamed.
     *
     * @return array<int, FeatureRequestStatus>
     */
    public function visibleStatuses(): array
    {
        $configured = $this->visible_statuses ?: FeatureRequestStatus::defaultVisible();

        // The stored value says *which* columns to show; the enum decides where
        // each one sits. Reading the order from the enum keeps a status enabled
        // after the fact in its place in the lifecycle, rather than appended to
        // the end of the roadmap.
        return array_values(array_filter(
            FeatureRequestStatus::cases(),
            fn (FeatureRequestStatus $status) => in_array($status->value, $configured, true)
        ));
    }

    /**
     * Whether a newly submitted request should appear on the board immediately.
     */
    public function autoPublishesSubmissions(): bool
    {
        return ! $this->require_approval;
    }

    /**
     * Whether approving a request should tell the store that asked for it.
     *
     * Requires moderation to be on. Without review a request is public the
     * moment it is submitted, so "approved" marks nothing the merchant can
     * see, and mailing them about it would be noise.
     */
    public function announcesApprovals(): bool
    {
        return $this->require_approval && $this->notify_on_approval;
    }

    /**
     * Where to send the heads-up about a new request, if anywhere.
     */
    public function newRequestRecipient(): ?string
    {
        return filled($this->new_request_email) ? $this->new_request_email : null;
    }
}
