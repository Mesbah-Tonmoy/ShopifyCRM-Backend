<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class VerifyWebhookSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get the signature from the request header
        $signature = $request->header('X-Webhook-Signature');
        
        if (!$signature) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook signature missing',
            ], 401);
        }

        // Get the webhook secret from config
        $secret = config('webhook.secret');
        
        if (!$secret) {
            Log::error('Webhook secret not configured');
            return response()->json([
                'success' => false,
                'message' => 'Webhook verification failed',
            ], 500);
        }

        // Get the raw request body
        $payload = $request->getContent();
        
        // Calculate the expected signature
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        
        // Compare signatures
        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Invalid webhook signature', [
                'expected' => $expectedSignature,
                'received' => $signature,
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook signature',
            ], 401);
        }

        return $next($request);
    }
}