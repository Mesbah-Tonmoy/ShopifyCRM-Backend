<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Google Chat space that is posted to, through its incoming webhook, when
 * one of its events happens in one of its apps.
 */
class GoogleChatSpace extends Model
{
    protected $fillable = [
        'name',
        'webhook_url',
        'events',
        'app_scope',
        'app_ids',
        'is_enabled',
    ];

    protected $casts = [
        'webhook_url' => 'encrypted',
        'events' => 'array',
        'app_ids' => 'array',
        'is_enabled' => 'boolean',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function wants(string $event, App $app): bool
    {
        if (!in_array($event, $this->events ?? [], true)) {
            return false;
        }

        return $this->app_scope === 'all'
            || in_array($app->id, array_map('intval', $this->app_ids ?? []), true);
    }
}
