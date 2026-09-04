<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureRequestVote extends Model
{
    use HasFactory;

    protected $table = 'feature_request_votes';

    protected $fillable = [
        'feature_request_id',
        'app_id',
        'installation_id',
        'voter_key',
        'weight',
        'ip_address',
    ];

    protected $casts = [
        'weight' => 'integer',
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
