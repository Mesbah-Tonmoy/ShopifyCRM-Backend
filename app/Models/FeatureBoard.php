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
}
