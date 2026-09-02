<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        static::deleting(function (Feature $feature) {
            if ($feature->image) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($feature->image);
            }
        });
    }

    /**
     * Get the app that owns this feature.
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    /**
     * Full public URL for the uploaded image.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->image) : null;
    }

    /**
     * Scope a query to only include published features.
     */
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    /**
     * Scope a query to get features for a specific app.
     */
    public function scopeForApp($query, int $appId)
    {
        return $query->where('app_id', $appId);
    }
}
