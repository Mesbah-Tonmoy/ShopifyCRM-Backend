<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected $casts = [
        'app_plan'          => 'array',
        'is_active'         => 'boolean',
        'install_count'     => 'integer',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
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
