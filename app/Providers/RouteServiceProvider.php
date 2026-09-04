<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Public board limits. Reads are generous, writes are throttled per
        // store so a single shop cannot flood a board.
        RateLimiter::for('board-read', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        RateLimiter::for('board-session', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('board-vote', function (Request $request) {
            return Limit::perMinute(30)->by($this->boardVoterKey($request));
        });

        RateLimiter::for('board-submit', function (Request $request) {
            return Limit::perMinute(5)->by($this->boardVoterKey($request));
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Throttle board writes by the verified store where one is present, so a
     * shared office IP does not rate-limit unrelated merchants.
     */
    protected function boardVoterKey(Request $request): string
    {
        $identity = \App\Http\Middleware\ResolveBoardSession::identity($request);

        return $identity?->voterKey ?: $request->ip();
    }
}
