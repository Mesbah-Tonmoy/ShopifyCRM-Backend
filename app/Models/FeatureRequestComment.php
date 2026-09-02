<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FeatureRequestComment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'feature_request_comments';

    protected $fillable = [
        'feature_request_id',
        'app_id',
        'installation_id',
        'author_shop_domain',
        'author_name',
        'user_id',
        'is_official',
        'body',
        'is_hidden',
    ];

    protected $casts = [
        'is_official' => 'boolean',
        'is_hidden' => 'boolean',
    ];

    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class, 'feature_request_id');
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class, 'installation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Comments merchants are allowed to read.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    /**
     * Name shown beside the comment. Official replies are attributed to the
     * team rather than to the individual staff member.
     */
    public function displayName(string $teamName): string
    {
        if ($this->is_official) {
            return $teamName;
        }

        return $this->author_name
            ?: ($this->relationLoaded('installation') ? $this->installation?->store_name : null)
            ?: 'A store';
    }
}
