<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureRequestSubscriber extends Model
{
    use HasFactory;

    protected $table = 'feature_request_subscribers';

    protected $fillable = [
        'feature_request_id',
        'app_id',
        'installation_id',
        'subscriber_key',
        'email',
    ];

    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class, 'feature_request_id');
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(Installation::class, 'installation_id');
    }
}
