<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanFeature extends Model
{
    use HasFactory;

    protected $table = 'plan_features';

    protected $fillable = [
        'app_id',
        'plan_id',
        'feature_id',
        'value',
    ];

    /**
     * Get the app that owns this plan feature.
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    /**
     * Get the pricing plan this feature belongs to.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PricingPlan::class, 'plan_id');
    }

    /**
     * Get the feature definition.
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(FeatureDefinition::class, 'feature_id');
    }

    /**
     * Get the parsed value based on the feature's value type.
     */
    public function getParsedValue()
    {
        if (!$this->feature) {
            return $this->value;
        }

        return $this->feature->parseValue($this->value);
    }

    /**
     * Check if the feature value is unlimited.
     */
    public function isUnlimited(): bool
    {
        return strtolower($this->value) === 'unlimited';
    }

    /**
     * Get the numeric value or return max int if unlimited.
     */
    public function getNumericValue(): int
    {
        if ($this->isUnlimited()) {
            return PHP_INT_MAX;
        }

        return is_numeric($this->value) ? (int) $this->value : 0;
    }

    /**
     * Get the boolean value.
     */
    public function getBooleanValue(): bool
    {
        return filter_var($this->value, FILTER_VALIDATE_BOOLEAN);
    }
}
