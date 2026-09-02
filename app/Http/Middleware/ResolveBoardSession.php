<?php

namespace App\Http\Middleware;

use App\Services\Board\BoardTokenService;
use App\Support\Board\BoardIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the verified store behind a board session and attaches it to the
 * request.
 *
 * Use `board.session` on write routes, where a trusted store is mandatory, and
 * `board.session:optional` on read routes, which stay publicly browsable but
 * show personalised vote state when a session is present.
 */
class ResolveBoardSession
{
    /**
     * Request attribute the resolved identity is stored under.
     */
    public const ATTRIBUTE = 'board_identity';

    public function __construct(protected BoardTokenService $tokens)
    {
    }

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $identity = $this->tokens->readSession($this->extractSession($request));

        if (! $identity && $mode !== 'optional') {
            return response()->json([
                'success' => false,
                'message' => 'Your board session has expired. Reload the page to continue.',
            ], 401);
        }

        $request->attributes->set(self::ATTRIBUTE, $identity);

        return $next($request);
    }

    /**
     * Read the session from an `Authorization: Board <session>` header, falling
     * back to a dedicated header for callers that cannot set Authorization.
     */
    protected function extractSession(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (preg_match('/^Board\s+(.+)$/i', trim($header), $matches)) {
            return trim($matches[1]);
        }

        return $request->header('X-Board-Session');
    }

    /**
     * Convenience accessor for controllers.
     */
    public static function identity(Request $request): ?BoardIdentity
    {
        return $request->attributes->get(self::ATTRIBUTE);
    }
}
