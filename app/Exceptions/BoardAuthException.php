<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Raised when a board token or session cannot be trusted. Rendered as a plain
 * 401 so a caller learns the handshake failed, but never why in detail.
 */
class BoardAuthException extends Exception
{
    public function __construct(string $message = 'Invalid or expired board token.')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
        ], 401);
    }
}
