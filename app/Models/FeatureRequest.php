<?php

namespace App\Models;

use App\Enums\FeatureRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class FeatureRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Window used to calculate trending requests.
     */
    public const TRENDING_DAYS = 30;

    /**
     * Significant words considered when loosely matching a title.
     */
    public const MAX_SEARCH_WORDS = 4;

    /**
     * Share of a title's significant words that must match before it is
     * offered as a possible duplicate.
     */
    public const MATCH_RATIO = 0.6;

    protected $table = 'feature_requests';

    protected $fillable = [
        'app_id',
        'installation_id',
        'submitter_shop_domain',
        'submitter_email',
        'title',
        'description',
        'image',
        'status',
        'status_note',
        'admin_note',
        'is_visible',
        'is_hidden',
        'is_pinned',
        'completed_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'status' => FeatureRequestStatus::class,
        'is_visible' => 'boolean',
        'is_hidden' => 'boolean',
        'is_pinned' => 'boolean',
        'votes_count' => 'integer',
        'completed_at' => 'datetime',
    ];

    protected $appends = [
        'image_url',
    ];

    protected static function booted(): void
    {
        static::deleting(function (FeatureRequest $request) {
            // Only drop the screenshot on a hard delete; a soft delete is reversible.
            if ($request->isForceDeleting() && $request->image) {
                Storage::disk('public')->delete($request->image);
            }
        });
    }

    /* -----------------------------------------------------------------
     | Relations
     | -----------------------------------------------------------------
     */

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class, 'installation_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(FeatureRequestVote::class, 'feature_request_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FeatureRequestComment::class, 'feature_request_id');
    }

    public function subscribers(): HasMany
    {
        return $this->hasMany(FeatureRequestSubscriber::class, 'feature_request_id');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(FeatureRequestStatusLog::class, 'feature_request_id')->latest('created_at');
    }

    /* -----------------------------------------------------------------
     | Accessors
     | -----------------------------------------------------------------
     */

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }

    /* -----------------------------------------------------------------
     | Scopes
     | -----------------------------------------------------------------
     */

    public function scopeForApp(Builder $query, int $appId): Builder
    {
        return $query->where('app_id', $appId);
    }

    /**
     * Requests the public board is allowed to show.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    /**
     * What a given store is allowed to see: everything published, plus its own
     * submissions while they are still awaiting moderation.
     *
     * When the board has moderation switched off, unreviewed requests are
     * public too. That is evaluated here rather than frozen onto each row at
     * submission time, so turning the setting on or off fills and empties the
     * Pending column immediately.
     */
    public function scopeVisibleTo(Builder $query, ?string $voterKey, ?FeatureBoard $board = null): Builder
    {
        // An explicit hide always wins, including over the submitter's own view.
        $query->where('is_hidden', false);

        return $query->where(function (Builder $q) use ($voterKey, $board) {
            $q->where('is_visible', true);

            if ($board && ! $board->require_approval) {
                $q->orWhere('status', FeatureRequestStatus::Pending->value);
            }

            if (filled($voterKey)) {
                $q->orWhere('submitter_shop_domain', $voterKey);
            }
        });
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Free-text search across the merchant-facing fields.
     *
     * `all` matches the phrase as typed, for the search box. `any` matches on
     * individual significant words, which is what surfaces "Sticky add-to-cart
     * bar" while someone is typing "sticky add to cart button" — the duplicate
     * hint on the submission form.
     */
    public function scopeSearch(Builder $query, ?string $term, string $mode = 'all'): Builder
    {
        if (blank($term)) {
            return $query;
        }

        if ($mode !== 'any') {
            return $query->where(function (Builder $q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }

        $words = collect(preg_split('/\s+/', mb_strtolower(trim($term))))
            ->filter(fn (string $word) => mb_strlen($word) > 3)
            ->take(self::MAX_SEARCH_WORDS)
            ->values();

        if ($words->isEmpty()) {
            // Nothing substantial to match on yet — a half-typed title like
            // "new ad". Loose matching must return no suggestions here; an
            // unfiltered query would offer up the whole board instead.
            return $query->whereRaw('1 = 0');
        }

        // Score each row by how many of the words its title contains, then keep
        // only the rows clearing the threshold. Matching on a single common
        // word ("cart", "button") would otherwise suggest unrelated requests.
        $score = $words->map(fn () => 'CASE WHEN title LIKE ? THEN 1 ELSE 0 END')->implode(' + ');
        $bindings = $words->map(fn (string $word) => "%{$word}%")->all();
        $threshold = max(1, (int) ceil($words->count() * self::MATCH_RATIO));

        return $query
            ->whereRaw("({$score}) >= {$threshold}", $bindings)
            ->orderByRaw("({$score}) DESC", $bindings);
    }

    /**
     * Shared ordering used by both the public board and the admin list,
     * so "most voted" means the same thing everywhere.
     */
    public function scopeSortedBy(Builder $query, ?string $sort): Builder
    {
        // Pinned requests lead the relevance-style orderings, but must not
        // override an explicit chronological sort, where a card jumping the
        // queue just reads as a broken sort.
        if (! in_array($sort, ['newest', 'oldest'], true)) {
            $query->orderByDesc('is_pinned');
        }

        return match ($sort) {
            'newest' => $query->latest('created_at'),
            'oldest' => $query->oldest('created_at'),
            'trending' => $query
                ->withCount(['votes as recent_votes_count' => fn ($q) => $q->where(
                    'feature_request_votes.created_at',
                    '>=',
                    now()->subDays(self::TRENDING_DAYS)
                )])
                ->orderByDesc('recent_votes_count')
                ->orderByDesc('votes_count'),
            default => $query->orderByDesc('votes_count')->latest('created_at'),
        };
    }

    /**
     * Eager-load whether the given store is following this request.
     */
    public function scopeWithSubscriptionState(Builder $query, ?string $voterKey): Builder
    {
        if (blank($voterKey)) {
            return $query;
        }

        return $query->withExists([
            'subscribers as is_subscribed' => fn ($q) => $q->where('subscriber_key', $voterKey),
        ]);
    }

    /**
     * Everything a board-facing response needs on a request.
     *
     * Every endpoint that returns a request to the board must go through this.
     * A response that skips part of it serialises the missing pieces as null or
     * zero, and the client — which merges the response into the card it already
     * has — then wipes good data. That is how both the comment count and the
     * store name went missing after a vote.
     */
    public function scopeWithBoardPayload(Builder $query, ?string $voterKey = null): Builder
    {
        return $query
            ->withVoterState($voterKey)
            ->withSubscriptionState($voterKey)
            ->withVisibleCommentsCount()
            ->with('installation:id,store_name');
    }

    /**
     * The same payload, for a model that has already been fetched.
     */
    public function loadBoardPayload(): static
    {
        $this->load('installation:id,store_name');
        $this->loadCount(['comments' => fn ($q) => $q->where('is_hidden', false)]);

        return $this;
    }

    /**
     * Count only the comments merchants can see.
     *
     * Defined once so every endpoint that returns a request carries the same
     * number — a response missing it serialises as zero and silently wipes the
     * count the client already had.
     */
    public function scopeWithVisibleCommentsCount(Builder $query): Builder
    {
        return $query->withCount(['comments' => fn ($q) => $q->where('is_hidden', false)]);
    }

    /**
     * Eager-load whether the given voter has already voted, avoiding an
     * N+1 lookup when rendering a list of requests.
     */
    public function scopeWithVoterState(Builder $query, ?string $voterKey): Builder
    {
        if (blank($voterKey)) {
            return $query;
        }

        return $query->withExists(['votes as has_voted' => fn ($q) => $q->where('voter_key', $voterKey)]);
    }

    /* -----------------------------------------------------------------
     | Helpers
     | -----------------------------------------------------------------
     */

    /**
     * Whether merchants can currently see this request on the public board.
     *
     * Mirrors scopeVisibleTo for a single row, so the admin UI never claims a
     * request is hidden while the board is happily showing it. Expects the
     * `app.board` relation to be loaded.
     */
    public function isPubliclyVisible(): bool
    {
        if ($this->is_hidden) {
            return false;
        }

        if ($this->is_visible) {
            return true;
        }

        $board = $this->relationLoaded('app') ? $this->app?->board : null;

        return $board !== null
            && ! $board->require_approval
            && $this->status === FeatureRequestStatus::Pending;
    }

    /**
     * Repair the denormalised counter from the votes table.
     */
    public function recountVotes(): int
    {
        $count = $this->votes()->count();

        $this->forceFill(['votes_count' => $count])->saveQuietly();

        return $count;
    }
}
