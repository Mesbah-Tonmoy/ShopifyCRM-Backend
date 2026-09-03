<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogWebhookRequest
{
    /**
     * Log every inbound webhook request and the response we send back.
     *
     * Webhooks are fire-and-forget from the Shopify app's point of view, so
     * without this we have no way of telling "the app never called us" apart
     * from "the app called us and we rejected it".
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('webhook_request_id', $requestId);

        $startedAt = microtime(true);

        Log::channel('webhooks')->info('Webhook request received', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'scheme' => $request->getScheme(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'content_type' => $request->header('Content-Type'),
            'content_length' => $request->header('Content-Length'),
            'has_signature' => $request->hasHeader('X-Webhook-Signature'),
            // A redirect hop in front of the CRM is the usual reason a POST
            // arrives as something else, so keep the proxy headers around.
            'forwarded_proto' => $request->header('X-Forwarded-Proto'),
            'forwarded_host' => $request->header('X-Forwarded-Host'),
            'payload' => $this->payload($request),
        ]);

        $response = $next($request);

        $context = [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ];

        if ($response->getStatusCode() >= 400) {
            $context['response'] = Str::limit((string) $response->getContent(), 2000);

            Log::channel('webhooks')->error('Webhook request failed', $context);
        } else {
            Log::channel('webhooks')->info('Webhook request handled', $context);
        }

        return $response;
    }

    /**
     * The request payload, or a marker describing why there isn't one.
     *
     * An empty body on a webhook endpoint is itself a symptom: a POST that got
     * rewritten to GET by a redirect arrives with its body stripped.
     *
     * @return array<string, mixed>|string
     */
    protected function payload(Request $request): array|string
    {
        $data = $request->all();

        if (empty($data)) {
            $raw = (string) $request->getContent();

            return $raw === '' ? '<empty body>' : Str::limit($raw, 2000);
        }

        return $data;
    }
}
