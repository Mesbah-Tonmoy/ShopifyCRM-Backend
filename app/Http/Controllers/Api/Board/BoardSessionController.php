<?php

namespace App\Http\Controllers\Api\Board;

use App\Http\Controllers\Controller;
use App\Services\Board\BoardTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardSessionController extends Controller
{
    public function __construct(protected BoardTokenService $tokens)
    {
    }

    /**
     * Exchange a token signed by the embedding Shopify app for a board session.
     *
     * The app's backend mints the token; the browser only ever relays it. That
     * is what makes one-vote-per-store enforceable.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:2048'],
        ]);

        $identity = $this->tokens->verifyAppToken($validated['token']);

        $board = $identity->app->board;

        if (! $board || ! $board->is_enabled) {
            return response()->json([
                'success' => false,
                'message' => 'This feature board is not currently available.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'session' => $this->tokens->issueSession($identity),
                'expires_in' => (int) config('board.session_ttl'),
                'board_slug' => $identity->app->board_slug,
                'voter' => $identity->toArray(),
            ],
        ]);
    }
}
