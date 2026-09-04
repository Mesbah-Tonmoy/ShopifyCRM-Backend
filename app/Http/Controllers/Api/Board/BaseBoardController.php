<?php

namespace App\Http\Controllers\Api\Board;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveBoardSession;
use App\Models\App;
use App\Models\FeatureBoard;
use App\Support\Board\BoardIdentity;
use Illuminate\Http\Request;

abstract class BaseBoardController extends Controller
{
    /**
     * Resolve the board for an app, refusing anything disabled or never set up.
     */
    protected function boardFor(App $app): FeatureBoard
    {
        $board = $app->board;

        abort_if(! $board || ! $board->is_enabled, 404, 'This feature board is not available.');

        return $board;
    }

    /**
     * The verified store, when the caller presented a session.
     */
    protected function identity(Request $request): ?BoardIdentity
    {
        $identity = ResolveBoardSession::identity($request);

        // A session minted for another app must never act on this board.
        if ($identity && $identity->app->id !== $request->route('app')?->id) {
            return null;
        }

        return $identity;
    }

    /**
     * The verified store on routes where the session middleware guarantees one.
     */
    protected function requireIdentity(Request $request): BoardIdentity
    {
        $identity = $this->identity($request);

        abort_if($identity === null, 401, 'Your board session is not valid for this board.');

        return $identity;
    }

    protected function voterKey(Request $request): ?string
    {
        return $this->identity($request)?->voterKey;
    }
}
