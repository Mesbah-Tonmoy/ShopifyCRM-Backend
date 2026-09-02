<?php

namespace App\Events;

use App\Models\FeatureRequest;
use App\Models\FeatureRequestStatusLog;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a request moves between statuses.
 *
 * Keeps notification concerns out of the service that performs the move: the
 * mail listener subscribes to this instead.
 */
class FeatureRequestStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly FeatureRequest $featureRequest,
        public readonly FeatureRequestStatusLog $log,
        public readonly bool $notify = true,
    ) {
    }
}
