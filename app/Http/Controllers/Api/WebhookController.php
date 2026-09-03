<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Installation;
use App\Services\EmailTemplateService;
use App\Services\SlackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Events\WebhookReceived;

class WebhookController extends Controller
{
    /**
     * Log channel for everything that happens on these endpoints.
     */
    private const LOG = 'webhooks';

    /**
     * A merchant gets at most one install (or uninstall) notification per
     * store within this window, no matter how often the app re-sends the
     * webhook. Guards against retry loops and hourly sync jobs.
     */
    private const EMAIL_COOLDOWN_HOURS = 24;

    /**
     * The endpoints this controller exposes, and the method each expects.
     * Used to answer misrouted requests with something actionable.
     *
     * @var array<string, string>
     */
    private const ENDPOINTS = [
        'install' => 'POST',
        'uninstall' => 'POST',
        'plan-change' => 'POST',
        'ping' => 'GET',
    ];

    /**
     * Handle app installation webhook
     */
    public function install(Request $request)
    {
        $requestId = $request->attributes->get('webhook_request_id');

        try {
            $validated = $request->validate([
                'app_url' => 'required|url',
                'shop_domain' => 'required|string',
                'shopify_shop_id' => 'nullable|string',
                'name' => 'required|string',
                'email' => 'required|email',
                'shop_owner_name' => 'nullable|string',
                'currency_code' => 'nullable|string',
                'primary_domain' => 'nullable|string',
                'shopify_plan' => 'nullable|string',
                'is_shopify_plus' => 'nullable|boolean',
                'timezone' => 'nullable|string',
                'installed_at' => 'nullable|date',
            ]);

            Log::channel(self::LOG)->info('Install webhook validated', [
                'request_id' => $requestId,
                'app_url' => $validated['app_url'],
                'shop_domain' => $validated['shop_domain'],
                'email' => $validated['email'],
            ]);

            // Find app
            $app = App::where('app_url', $validated['app_url'])->first();

            if (!$app) {
                // The install is lost until the app is connected in the CRM,
                // so keep the whole payload: it is enough to replay by hand.
                Log::channel(self::LOG)->error('Install webhook rejected: app not connected in the CRM', [
                    'request_id' => $requestId,
                    'app_url' => $validated['app_url'],
                    'shop_domain' => $validated['shop_domain'],
                    'payload' => $validated,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'App not found. Please connect the app first.',
                ], 404);
            }

            // What kind of install is this? Decided before the write, because
            // updateOrCreate() erases the distinction.
            $existing = Installation::where('app_id', $app->id)
                ->where('store_url', $validated['shop_domain'])
                ->first();

            $isNewInstall = $existing === null;
            $isReinstall = $existing !== null && !$existing->is_active;

            $attributes = [
                'store_name' => $validated['name'],
                'email' => $validated['email'],
                'is_active' => true,
            ];

            // Optional fields are written only when the payload carries them,
            // so a repeat delivery with a thinner payload cannot erase what we
            // already recorded for the store.
            if (!empty($validated['shop_owner_name'])) {
                $attributes['shop_owner_name'] = $validated['shop_owner_name'];
            }

            if (!empty($validated['currency_code'])) {
                $attributes['currency'] = $validated['currency_code'];
            } elseif ($isNewInstall) {
                $attributes['currency'] = 'USD';
            }

            if (!empty($validated['shopify_plan'])) {
                $attributes['shopify_plan'] = $validated['shopify_plan'];
            }

            if (!empty($validated['installed_at'])) {
                $attributes['installed_at'] = $validated['installed_at'];
            }

            // A new install - and a genuine reinstall - starts on Free. A
            // repeat delivery must not reset a paying merchant back to Free,
            // which is what happened on every duplicate webhook before.
            if ($isNewInstall || $isReinstall) {
                $attributes['app_plan'] = ['plan_name' => 'Free'];
            }

            // Create or update installation
            $installation = Installation::updateOrCreate(
                [
                    'app_id' => $app->id,
                    'store_url' => $validated['shop_domain'],
                ],
                $attributes
            );

            // Count real reinstalls only. Counting every inbound webhook (the
            // previous behaviour) inflated install_count on every retry.
            if ($isReinstall) {
                $installation->increment('install_count');
                $installation->refresh();
            }

            Log::channel(self::LOG)->info('Installation created/updated', [
                'request_id' => $requestId,
                'installation_id' => $installation->id,
                'app_id' => $app->id,
                'store_url' => $installation->store_url,
                'install_type' => $isNewInstall ? 'new' : ($isReinstall ? 'reinstall' : 'duplicate'),
                'install_count' => $installation->install_count,
            ]);

            // Broadcast event
            WebhookReceived::dispatch($installation->store_url, 'install');

            $this->sendInstallEmail($installation, $isNewInstall, $isReinstall, $requestId);

            return response()->json([
                'success' => true,
                'message' => 'Installation recorded successfully',
                'data' => $installation,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel(self::LOG)->error('Validation error in install webhook', [
                'request_id' => $requestId,
                'errors' => $e->errors(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::channel(self::LOG)->error('Error in install webhook', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'at' => $e->getFile() . ':' . $e->getLine(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process installation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle app uninstallation webhook
     */
    public function uninstall(Request $request)
    {
        $requestId = $request->attributes->get('webhook_request_id');

        try {
            $validated = $request->validate([
                'app_url' => 'required|url',
                'shop_domain' => 'required|string',
            ]);

            Log::channel(self::LOG)->info('Uninstall webhook validated', [
                'request_id' => $requestId,
                'app_url' => $validated['app_url'],
                'shop_domain' => $validated['shop_domain'],
            ]);

            // Find app
            $app = App::where('app_url', $validated['app_url'])->first();

            if (!$app) {
                Log::channel(self::LOG)->error('Uninstall webhook rejected: app not connected in the CRM', [
                    'request_id' => $requestId,
                    'app_url' => $validated['app_url'],
                    'shop_domain' => $validated['shop_domain'],
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'App not found',
                ], 404);
            }

            // Find and update installation
            $installation = Installation::where('app_id', $app->id)
                ->where('store_url', $validated['shop_domain'])
                ->first();

            if (!$installation) {
                Log::channel(self::LOG)->error('Uninstall webhook rejected: installation not found', [
                    'request_id' => $requestId,
                    'app_id' => $app->id,
                    'shop_domain' => $validated['shop_domain'],
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Installation not found',
                ], 404);
            }

            // Only the first uninstall webhook is a state change; later ones
            // are repeats of a store that is already marked inactive.
            $wasActive = (bool) $installation->is_active;

            // Get current app_plan and update status
            $appPlan = $installation->app_plan ?? [];
            $appPlan['status'] = 'CANCELLED';
            $appPlan['plan_expires_at'] = null;

            // Mark as inactive and update plan
            $installation->update([
                'is_active' => false,
                'app_plan' => $appPlan,
            ]);

            Log::channel(self::LOG)->info('Installation marked as inactive', [
                'request_id' => $requestId,
                'installation_id' => $installation->id,
                'store_url' => $installation->store_url,
                'uninstall_type' => $wasActive ? 'new' : 'duplicate',
            ]);

            // Broadcast event
            WebhookReceived::dispatch($installation->store_url, 'uninstall');

            $this->sendUninstallEmail($installation, $wasActive, $requestId);

            return response()->json([
                'success' => true,
                'message' => 'Uninstallation recorded successfully',
                'data' => $installation,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel(self::LOG)->error('Validation error in uninstall webhook', [
                'request_id' => $requestId,
                'errors' => $e->errors(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::channel(self::LOG)->error('Error in uninstall webhook', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'at' => $e->getFile() . ':' . $e->getLine(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process uninstallation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle plan change webhook
     */
    public function planChange(Request $request)
    {
        $requestId = $request->attributes->get('webhook_request_id');

        try {
            $validated = $request->validate([
                'app_url' => 'required|url',
                'shop_domain' => 'required|string',
                'app_plan' => 'required|array',
                'app_plan.plan_name' => 'nullable|string',
                'app_plan.test' => 'nullable|boolean',
                'app_plan.has_trial' => 'nullable|boolean',
                'app_plan.trial_days' => 'nullable|integer|min:0',
                'app_plan.remaining_trial_days' => 'nullable|integer|min:0',
                'app_plan.plan_started_at' => 'nullable|date',
                'app_plan.plan_expires_at' => 'nullable|date',
                'app_plan.status' => 'nullable|string',
            ]);

            Log::channel(self::LOG)->info('Plan change webhook validated', [
                'request_id' => $requestId,
                'app_url' => $validated['app_url'],
                'shop_domain' => $validated['shop_domain'],
                'app_plan' => $validated['app_plan'],
            ]);

            // Find app
            $app = App::where('app_url', $validated['app_url'])->first();

            if (!$app) {
                Log::channel(self::LOG)->error('Plan change webhook rejected: app not connected in the CRM', [
                    'request_id' => $requestId,
                    'app_url' => $validated['app_url'],
                    'shop_domain' => $validated['shop_domain'],
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'App not found',
                ], 404);
            }

            // Find and update installation
            $installation = Installation::where('app_id', $app->id)
                ->where('store_url', $validated['shop_domain'])
                ->first();

            if (!$installation) {
                Log::channel(self::LOG)->error('Plan change webhook rejected: installation not found', [
                    'request_id' => $requestId,
                    'app_id' => $app->id,
                    'shop_domain' => $validated['shop_domain'],
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Installation not found',
                ], 404);
            }

            // Update plan information
            $installation->update([
                'app_plan' => $validated['app_plan'],
            ]);

            Log::channel(self::LOG)->info('Plan updated', [
                'request_id' => $requestId,
                'installation_id' => $installation->id,
                'store_url' => $installation->store_url,
                'new_plan' => $validated['app_plan'],
            ]);

            // Notify Slack of the plan change, if enabled
            try {
                $planName = $validated['app_plan']['plan_name'] ?? 'Unknown';
                $status = strtoupper($validated['app_plan']['status'] ?? '');

                $headingText = match ($status) {
                    'CANCELLED' => ':x: *Plan Cancelled*',
                    'ACTIVE' => ':tada: *New Plan Activated*',
                    default => ':moneybag: *Plan Changed*',
                };
                $heading = "{$headingText} ({$app->app_name})";

                (new SlackService())->sendSections(
                    $heading,
                    "*Plan:* {$planName}\n*Store:* {$installation->store_name} (`{$installation->store_url}`)"
                );
            } catch (\Exception $e) {
                Log::channel(self::LOG)->error('Failed to send Slack plan-change notification', [
                    'request_id' => $requestId,
                    'error' => $e->getMessage(),
                    'installation_id' => $installation->id,
                ]);
                // Don't fail the webhook if Slack notification fails
            }

            return response()->json([
                'success' => true,
                'message' => 'Plan change recorded successfully',
                'data' => $installation,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel(self::LOG)->error('Validation error in plan change webhook', [
                'request_id' => $requestId,
                'errors' => $e->errors(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::channel(self::LOG)->error('Error in plan change webhook', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'at' => $e->getFile() . ':' . $e->getLine(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process plan change: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reachability check.
     *
     * Lets the app side (or ops) verify that a base URL actually reaches this
     * API - and over which scheme - before webhooks are pointed at it.
     */
    public function ping(Request $request)
    {
        Log::channel(self::LOG)->info('Webhook ping', [
            'request_id' => $request->attributes->get('webhook_request_id'),
            'ip' => $request->ip(),
            'scheme' => $request->getScheme(),
            'host' => $request->getHost(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'CRM webhook endpoints are reachable',
            'received_over' => $request->getScheme(),
            'endpoints' => [
                'install' => 'POST ' . url('/api/webhooks/install'),
                'uninstall' => 'POST ' . url('/api/webhooks/uninstall'),
                'plan-change' => 'POST ' . url('/api/webhooks/plan-change'),
            ],
        ]);
    }

    /**
     * Anything that reaches /api/webhooks/* but matches no endpoint.
     *
     * Without this, a POST that gets rewritten to GET (which is what Guzzle -
     * and therefore Laravel's Http client - does when it follows a 301/302)
     * produced a bare 405 with nothing in our logs to explain it, while the
     * app side just saw "webhook failed, status 405".
     */
    public function unhandled(Request $request, ?string $path = null)
    {
        $target = trim((string) $path, '/');
        $expectedMethod = self::ENDPOINTS[$target] ?? null;

        $context = [
            'request_id' => $request->attributes->get('webhook_request_id'),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'target' => $target === '' ? '<none>' : $target,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'content_type' => $request->header('Content-Type'),
            'referer' => $request->header('Referer'),
            'forwarded_proto' => $request->header('X-Forwarded-Proto'),
            'forwarded_host' => $request->header('X-Forwarded-Host'),
            'query' => $request->query(),
            'body_bytes' => strlen((string) $request->getContent()),
        ];

        if ($expectedMethod !== null && $request->method() !== $expectedMethod) {
            $hint = $request->isMethod('GET')
                ? 'A GET reached a POST-only webhook endpoint. This is what a redirect does to a POST: '
                    . 'the HTTP client rewrites the request to GET and drops the body when it follows a 301/302 '
                    . '(http -> https, non-www -> www, or a proxy redirect). Point CRM_URL in the Shopify app at '
                    . 'the final scheme/host so no redirect hop is involved.'
                : "This endpoint only accepts {$expectedMethod}.";

            Log::channel(self::LOG)->error('Webhook rejected: method not allowed', $context + [
                'expected_method' => $expectedMethod,
                'hint' => $hint,
            ]);

            return response()->json([
                'success' => false,
                'message' => "Method {$request->method()} not allowed for /api/webhooks/{$target}; expected {$expectedMethod}.",
                'hint' => $hint,
            ], 405, ['Allow' => $expectedMethod]);
        }

        Log::channel(self::LOG)->error('Webhook rejected: unknown endpoint', $context + [
            'known_endpoints' => array_keys(self::ENDPOINTS),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Unknown webhook endpoint: /api/webhooks/' . $target,
            'known_endpoints' => array_keys(self::ENDPOINTS),
        ], 404);
    }

    /**
     * Send the installation email, unless this webhook is a repeat.
     *
     * The install webhook is not guaranteed to arrive once - retries and
     * periodic sync jobs on the app side re-send it for stores that are
     * already recorded. Sending on every delivery is what produced an
     * installation email every hour for the same merchant.
     */
    private function sendInstallEmail(
        Installation $installation,
        bool $isNewInstall,
        bool $isReinstall,
        ?string $requestId
    ): void {
        $skipReason = null;

        if (!$isNewInstall && !$isReinstall) {
            $skipReason = 'duplicate install webhook for an installation that is already active';
        } elseif ($this->sentWithinCooldown($installation->install_email_sent_at)) {
            $skipReason = 'install email already sent at '
                . $installation->install_email_sent_at->toIso8601String()
                . ' (within the ' . self::EMAIL_COOLDOWN_HOURS . 'h cooldown)';
        } elseif (empty($installation->email)) {
            $skipReason = 'installation has no email address';
        }

        if ($skipReason !== null) {
            Log::channel(self::LOG)->info('Install email skipped', [
                'request_id' => $requestId,
                'installation_id' => $installation->id,
                'store_url' => $installation->store_url,
                'recipient' => $installation->email,
                'reason' => $skipReason,
            ]);

            return;
        }

        try {
            $sent = (new EmailTemplateService())->sendInstallationEmail($installation);

            if ($sent) {
                $installation->update(['install_email_sent_at' => now()]);

                Log::channel(self::LOG)->info('Install email sent', [
                    'request_id' => $requestId,
                    'installation_id' => $installation->id,
                    'store_url' => $installation->store_url,
                    'recipient' => $installation->email,
                    'install_type' => $isNewInstall ? 'new' : 'reinstall',
                ]);
            } else {
                // The service already logged why on the `emails` channel.
                Log::channel(self::LOG)->warning('Install email not sent', [
                    'request_id' => $requestId,
                    'installation_id' => $installation->id,
                    'store_url' => $installation->store_url,
                    'recipient' => $installation->email,
                ]);
            }
        } catch (\Exception $e) {
            Log::channel(self::LOG)->error('Failed to send installation email', [
                'request_id' => $requestId,
                'installation_id' => $installation->id,
                'recipient' => $installation->email,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            // Don't fail the webhook if email sending fails
        }
    }

    /**
     * Send the uninstallation email, unless this webhook is a repeat.
     */
    private function sendUninstallEmail(
        Installation $installation,
        bool $wasActive,
        ?string $requestId
    ): void {
        $skipReason = null;

        if (!$wasActive) {
            $skipReason = 'duplicate uninstall webhook for an installation that is already inactive';
        } elseif ($this->sentWithinCooldown($installation->uninstall_email_sent_at)) {
            $skipReason = 'uninstall email already sent at '
                . $installation->uninstall_email_sent_at->toIso8601String()
                . ' (within the ' . self::EMAIL_COOLDOWN_HOURS . 'h cooldown)';
        } elseif (empty($installation->email)) {
            $skipReason = 'installation has no email address';
        }

        if ($skipReason !== null) {
            Log::channel(self::LOG)->info('Uninstall email skipped', [
                'request_id' => $requestId,
                'installation_id' => $installation->id,
                'store_url' => $installation->store_url,
                'recipient' => $installation->email,
                'reason' => $skipReason,
            ]);

            return;
        }

        try {
            $sent = (new EmailTemplateService())->sendUninstallationEmail($installation);

            if ($sent) {
                $installation->update(['uninstall_email_sent_at' => now()]);

                Log::channel(self::LOG)->info('Uninstall email sent', [
                    'request_id' => $requestId,
                    'installation_id' => $installation->id,
                    'store_url' => $installation->store_url,
                    'recipient' => $installation->email,
                ]);
            } else {
                Log::channel(self::LOG)->warning('Uninstall email not sent', [
                    'request_id' => $requestId,
                    'installation_id' => $installation->id,
                    'store_url' => $installation->store_url,
                    'recipient' => $installation->email,
                ]);
            }
        } catch (\Exception $e) {
            Log::channel(self::LOG)->error('Failed to send uninstallation email', [
                'request_id' => $requestId,
                'installation_id' => $installation->id,
                'recipient' => $installation->email,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            // Don't fail the webhook if email sending fails
        }
    }

    /**
     * Was an email of this kind already sent inside the cooldown window?
     */
    private function sentWithinCooldown(?\Illuminate\Support\Carbon $sentAt): bool
    {
        return $sentAt !== null && $sentAt->gt(now()->subHours(self::EMAIL_COOLDOWN_HOURS));
    }
}
