<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A "what's new" entry: something shipped, announced to the stores using the
 * app.
 *
 * Deliberately independent of FeatureRequest. Plenty of what ships was never
 * asked for, and an entry should be publishable without inventing a request to
 * hang it on.
 */
class Feature extends Model
{
    use HasFactory;

    protected $table = 'features';

    protected $fillable = [
        'app_id',
        'title',
        'description',
        'image',
        'release_date',
        'is_published',
    ];

    protected $casts = [
        'release_date' => 'date',
        'is_published' => 'boolean',
    ];

    protected $appends = [
        'image_url',
    ];

    protected static function booted(): void
    {
        // Otherwise the upload outlives the row it belonged to.
        static::deleting(function (Feature $feature) {
            if ($feature->image) {
                Storage::disk('public')->delete($feature->image);
            }
        });
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    /**
     * Full public URL for the uploaded image.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }

    /* -----------------------------------------------------------------
     | Scopes
     | -----------------------------------------------------------------
     |
     | Filtering lives here rather than in the controller, so the admin list,
     | the per-app list and the public changelog compose the same query.
     */

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeForApp(Builder $query, int $appId): Builder
    {
        return $query->where('app_id', $appId);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        // Escaped so a merchant searching for "50% off" does not turn the
        // wildcard loose on the whole table.
        $like = '%' . addcslashes($term, '%_\\') . '%';

        return $query->where(function (Builder $inner) use ($like) {
            $inner->where('title', 'like', $like)
                ->orWhere('description', 'like', $like);
        });
    }

    public function scopeReleasedBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q, string $date) => $q->whereDate('release_date', '>=', $date))
            ->when($to, fn (Builder $q, string $date) => $q->whereDate('release_date', '<=', $date));
    }
}
