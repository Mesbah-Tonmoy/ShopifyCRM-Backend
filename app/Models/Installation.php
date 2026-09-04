<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Installation extends Model
{
    use HasFactory;

    protected $table = 'installations';

    protected $fillable = [
        'id',
        'app_id',
        'store_name',
        'store_url',
        'email',
        'shop_owner_name',
        'currency',
        'shopify_plan',
        'app_plan',
        'is_active',
        'install_count',
        'plan_started_at',
        'plan_expires_at',
        'installed_at',
        'install_email_sent_at',
        'uninstall_email_sent_at',
    ];

    protected $casts = [
        'app_plan'                 => 'array',
        'is_active'                => 'boolean',
        'install_count'            => 'integer',
        'plan_started_at'          => 'datetime',
        'plan_expires_at'          => 'datetime',
        'installed_at'             => 'datetime',
        'install_email_sent_at'    => 'datetime',
        'uninstall_email_sent_at'  => 'datetime',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    /**
     * Feature requests submitted by this store.
     */
    public function featureRequests(): HasMany
    {
        return $this->hasMany(FeatureRequest::class, 'installation_id');
    }

    /**
     * Board votes cast by this store.
     */
    public function featureRequestVotes(): HasMany
    {
        return $this->hasMany(FeatureRequestVote::class, 'installation_id');
    }

    /**
     * Scope a query to only include active installations.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }


    /**
     * Increment install count.
     */
    public function incrementInstallCount(): void
    {
        $this->increment('install_count');
    }
}
