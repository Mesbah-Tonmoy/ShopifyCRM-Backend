<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // A webhook that misses the /api/webhooks/* prefix entirely (wrong
        // base URL on the app side, missing /api segment, ...) never reaches
        // WebhookController, so log it here - otherwise the app side sees a
        // 404/405 that leaves no trace at all on our end.
        $this->renderable(function (MethodNotAllowedHttpException $e, Request $request) {
            if (!$this->looksLikeWebhook($request)) {
                return null;
            }

            $this->logMisroutedWebhook($request, 'method not allowed', [
                'allowed_methods' => $e->getHeaders()['Allow'] ?? null,
            ]);

            return null;
        });

        $this->renderable(function (NotFoundHttpException $e, Request $request) {
            if (!$this->looksLikeWebhook($request)) {
                return null;
            }

            $this->logMisroutedWebhook($request, 'no such route');

            return null;
        });
    }

    /**
     * Does this request look like a webhook delivery that missed its route?
     */
    protected function looksLikeWebhook(Request $request): bool
    {
        return Str::contains($request->path(), 'webhook', ignoreCase: true);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function logMisroutedWebhook(Request $request, string $reason, array $extra = []): void
    {
        Log::channel('webhooks')->error('Webhook delivery did not match any route: ' . $reason, array_merge([
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'content_type' => $request->header('Content-Type'),
            'forwarded_proto' => $request->header('X-Forwarded-Proto'),
            'forwarded_host' => $request->header('X-Forwarded-Host'),
            'body_bytes' => strlen((string) $request->getContent()),
            'expected_base_path' => '/api/webhooks',
        ], $extra));
    }
}
