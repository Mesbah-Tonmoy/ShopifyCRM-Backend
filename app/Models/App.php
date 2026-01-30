<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class App extends Model
{
    use HasFactory;

    protected $table = 'apps';

    protected $fillable = [
        'id',
        'app_name',
        'app_url',
        'app_store_url',
        'icon',
        'last_synced',
    ];

    protected $casts = [
        'last_synced' => 'datetime',
    ];

    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class, 'app_id');
    }

    /**
     * Get active installations for the app.
     */
    public function activeInstallations(): HasMany
    {
        return $this->hasMany(Installation::class)->where('is_active', true);
    }

    /**
     * Get email templates for the app.
     */
    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class, 'app_id');
    }

    /**
     * Get pricing plans for the app.
     */
    public function pricingPlans(): HasMany
    {
        return $this->hasMany(PricingPlan::class, 'app_id');
    }

    /**
     * Get total installation count.
     */
    public function getTotalInstallationsAttribute(): int
    {
        return $this->installations()->count();
    }

    /**
     * Get active installation count.
     */
    public function getActiveInstallationsCountAttribute(): int
    {
        return $this->activeInstallations()->count();
    }
}
