<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Integration extends Model
{
    use HasFactory;

    protected $table = 'integrations';

    protected $fillable = [
        'key',
        'name',
        'is_enabled',
        'config',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'config' => 'encrypted:array',
    ];

    /**
     * Scope a query to only include enabled integrations.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public static function findByKey(string $key): ?self
    {
        return static::where('key', $key)->first();
    }
}
