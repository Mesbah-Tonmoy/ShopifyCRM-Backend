<?php

namespace App\Models;

use App\Enums\FeatureRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureRequestStatusLog extends Model
{
    use HasFactory;

    protected $table = 'feature_request_status_logs';

    /**
     * Transitions are append-only, so only the creation time is tracked.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'feature_request_id',
        'from_status',
        'to_status',
        'note',
        'user_id',
        'notified_at',
    ];

    protected $casts = [
        'from_status' => FeatureRequestStatus::class,
        'to_status' => FeatureRequestStatus::class,
        'notified_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class, 'feature_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Whether notification emails for this transition have already been queued.
     */
    public function wasNotified(): bool
    {
        return $this->notified_at !== null;
    }
}
