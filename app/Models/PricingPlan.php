<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PricingPlan extends Model
{
    use HasFactory;

    protected $table = 'pricing_plans';

    protected $fillable = [
        'app_id',
        'name',
        'display_name',
        'amount',
        'currency_code',
        'interval',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'float',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Get the app that owns this pricing plan.
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    /**
     * Get all features for this plan.
     */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class, 'plan_id');
    }

    /**
     * Scope a query to only include active plans.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by interval.
     */
    public function scopeByInterval($query, string $interval)
    {
        return $query->where('interval', $interval);
    }

    /**
     * Get features with their values and definitions.
     */
    public function getFeaturesWithValues()
    {
        return $this->features()
            ->with('feature')
            ->get()
            ->map(function ($planFeature) {
                return [
                    'key' => $planFeature->feature->key,
                    'name' => $planFeature->feature->name,
                    'description' => $planFeature->feature->description,
                    'value' => $planFeature->getParsedValue(),
                    'category' => $planFeature->feature->category,
                ];
            });
    }

    /**
     * Check if plan is free.
     */
    public function isFree(): bool
    {
        return $this->interval === 'FREE' || $this->amount == 0;
    }
}
