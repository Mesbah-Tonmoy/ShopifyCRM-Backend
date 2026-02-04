<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Installation;
use App\Services\EmailTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Events\WebhookReceived;

class WebhookController extends Controller
{
    /**
     * Handle app installation webhook
     */
    public function install(Request $request)
    {
        Log::channel('stderr')->info('app url: ' . $request->app_url);
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

            Log::channel('stderr')->info('Installation webhook received', $validated);

            // Find or create app
            $app = App::where('app_url', $validated['app_url'])->first();
            
            if (!$app) {
                return response()->json([
                    'success' => false,
                    'message' => 'App not found. Please connect the app first.',
                ], 404);
            }

            // Create or update installation
            $installation = Installation::updateOrCreate(
                [
                    'app_id' => $app->id,
                    'store_url' => $validated['shop_domain'],
                ],
                [
                    'store_name' => $validated['name'],
                    'email' => $validated['email'],
                    'shop_owner_name' => $validated['shop_owner_name'] ?? null,
                    'currency' => $validated['currency_code'] ?? 'USD',
                    'shopify_plan' => $validated['shopify_plan'] ?? null,
                    'app_plan' => ['plan_name' => 'free'],
                    'installed_at' => $validated['installed_at'] ?? null,
                    'is_active' => true,
                ]
            );

            // Increment install count if it's a reinstall
            if ($installation->wasRecentlyCreated === false) {
                $installation->increment('install_count');
            }

            Log::channel('stderr')->info('Installation created/updated', ['installation_id' => $installation->id]);

            // Broadcast event
            WebhookReceived::dispatch($installation->store_url, 'install');

            // Send installation email
            try {
                $emailService = new EmailTemplateService();
                $emailService->sendInstallationEmail($installation);
            } catch (\Exception $e) {
                Log::channel('stderr')->error('Failed to send installation email', [
                    'error' => $e->getMessage(),
                    'installation_id' => $installation->id,
                ]);
                // Don't fail the webhook if email sending fails
            }

            return response()->json([
                'success' => true,
                'message' => 'Installation recorded successfully',
                'data' => $installation,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('stderr')->error('Validation error in install webhook', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::channel('stderr')->error('Error in install webhook', ['error' => $e->getMessage()]);
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
        try {
            $validated = $request->validate([
                'app_url' => 'required|url',
                'shop_domain' => 'required|string',
            ]);

            Log::channel('stderr')->info('Uninstallation webhook received', $validated);

            // Find app
            $app = App::where('app_url', $validated['app_url'])->first();
            
            if (!$app) {
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
                return response()->json([
                    'success' => false,
                    'message' => 'Installation not found',
                ], 404);
            }

            // Get current app_plan and update status
            $appPlan = $installation->app_plan ?? [];
            $appPlan['status'] = 'CANCELLED';
            $appPlan['plan_expires_at'] = null;

            // Mark as inactive and update plan
            $installation->update([
                'is_active' => false,
                'app_plan' => $appPlan,
            ]);

            Log::channel('stderr')->info('Installation marked as inactive', ['installation_id' => $installation->id]);

            // Broadcast event
            WebhookReceived::dispatch($installation->store_url, 'uninstall');

            // Send uninstallation email
            try {
                $emailService = new EmailTemplateService();
                $emailService->sendUninstallationEmail($installation);
            } catch (\Exception $e) {
                Log::channel('stderr')->error('Failed to send uninstallation email', [
                    'error' => $e->getMessage(),
                    'installation_id' => $installation->id,
                ]);
                // Don't fail the webhook if email sending fails
            }

            return response()->json([
                'success' => true,
                'message' => 'Uninstallation recorded successfully',
                'data' => $installation,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('stderr')->error('Validation error in uninstall webhook', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::channel('stderr')->error('Error in uninstall webhook', ['error' => $e->getMessage()]);
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

            Log::channel('stderr')->info('Plan change webhook received', $validated);

            // Find app
            $app = App::where('app_url', $validated['app_url'])->first();
            
            if (!$app) {
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
                return response()->json([
                    'success' => false,
                    'message' => 'Installation not found',
                ], 404);
            }

            // Update plan information
            $installation->update([
                'app_plan' => $validated['app_plan'],
            ]);

            Log::channel('stderr')->info('Plan updated', [
                'installation_id' => $installation->id,
                'new_plan' => $validated['app_plan']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Plan change recorded successfully',
                'data' => $installation,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('stderr')->error('Validation error in plan change webhook', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::channel('stderr')->error('Error in plan change webhook', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to process plan change: ' . $e->getMessage(),
            ], 500);
        }
    }
}