<?php

namespace App\Models;

use App\Enums\FeatureRequestStatus;
use App\Support\AddressList;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'hide_pending_requests',
        'show_vote_counts',
        'notify_on_status_change',
        'new_request_email',
        'new_request_cc',
        'new_request_bcc',
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
        'hide_pending_requests' => 'boolean',
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
     *
     * Boards show everything by default. Switching `hide_pending_requests` on
     * keeps new requests off the public board until an admin publishes them.
     */
    public function autoPublishesSubmissions(): bool
    {
        return ! $this->hide_pending_requests;
    }

    /**
     * Who hears about a new request: the To list, plus any cc and bcc.
     *
     * Returned together because a send needs all three at once, and because
     * the single-method shape keeps these names clear of Laravel's attribute
     * mutators below, which must be called after the columns they normalise.
     *
     * @return array{to: array<int, string>, cc: array<int, string>, bcc: array<int, string>}
     */
    public function newRequestAudience(): array
    {
        return [
            'to' => AddressList::parse($this->new_request_email),
            'cc' => AddressList::parse($this->new_request_cc),
            'bcc' => AddressList::parse($this->new_request_bcc),
        ];
    }

    /*
     | Address lists are stored in the form they are typed - comma separated -
     | but normalised on the way in, so a stray space or a trailing comma
     | cannot become an empty recipient.
     */

    protected function newRequestEmail(): Attribute
    {
        return Attribute::set(fn ($value) => AddressList::normalise($value));
    }

    protected function newRequestCc(): Attribute
    {
        return Attribute::set(fn ($value) => AddressList::normalise($value));
    }

    protected function newRequestBcc(): Attribute
    {
        return Attribute::set(fn ($value) => AddressList::normalise($value));
    }
}
