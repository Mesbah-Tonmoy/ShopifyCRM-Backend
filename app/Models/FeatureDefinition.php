<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeatureDefinition extends Model
{
    use HasFactory;

    protected $table = 'feature_definitions';

    protected $fillable = [
        'app_id',
        'key',
        'name',
        'description',
        'value_type',
        'category',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the app that owns this feature definition.
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    /**
     * Get all plan features that use this definition.
     */
    public function planFeatures(): HasMany
    {
        return $this->hasMany(PlanFeature::class, 'feature_id');
    }

    /**
     * Scope a query to only include active features.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by category.
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Parse a value based on this feature's value type.
     */
    public function parseValue(string $value)
    {
        return match ($this->value_type) {
            'NUMBER' => is_numeric($value) ? (int) $value : 0,
            'BOOLEAN' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'UNLIMITED' => $value === 'unlimited' ? PHP_INT_MAX : $value,
            default => $value,
        };
    }

    /**
     * Check if feature is unlimited type.
     */
    public function isUnlimited(): bool
    {
        return $this->value_type === 'UNLIMITED';
    }

    /**
     * Check if feature is boolean type.
     */
    public function isBoolean(): bool
    {
        return $this->value_type === 'BOOLEAN';
    }

    /**
     * Check if feature is number type.
     */
    public function isNumber(): bool
    {
        return $this->value_type === 'NUMBER';
    }
}
