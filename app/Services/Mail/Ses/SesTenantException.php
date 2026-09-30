<?php

namespace App\Services\Mail\Ses;

use RuntimeException;

/**
 * A tenant operation that could not be completed. The message is safe to show.
 */
class SesTenantException extends RuntimeException
{
}
