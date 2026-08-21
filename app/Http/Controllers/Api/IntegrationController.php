<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    /**
     * Default integrations available in the app.
     */
    protected const DEFAULTS = [
        'slack' => 'Slack',
        'gmail' => 'Gmail',
        'sendgrid' => 'SendGrid',
        'mailtrap' => 'Mailtrap',
    ];

    /**
     * Fields required before an integration can be enabled.
     */
    protected const REQUIRED_FIELDS = [
        'sendgrid' => ['api_key', 'from_email'],
        'mailtrap' => ['username', 'password', 'from_email'],
    ];

    /**
     * Get all integrations, provisioning default rows on first access.
     */
    public function index()
    {
        foreach (self::DEFAULTS as $key => $name) {
            Integration::firstOrCreate(['key' => $key], ['name' => $name, 'is_enabled' => false]);
        }

        return response()->json([
            'success' => true,
            'data' => Integration::orderBy('key')->get(),
        ]);
    }

    /**
     * Update an integration's config and enabled state.
     */
    public function update(Request $request, string $key)
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            return response()->json([
                'success' => false,
                'message' => 'Unknown integration',
            ], 404);
        }

        $validated = $request->validate([
            'is_enabled' => 'boolean',
            'config' => 'array',
        ]);

        $integration = Integration::firstOrCreate(
            ['key' => $key],
            ['name' => self::DEFAULTS[$key], 'is_enabled' => false]
        );

        $enabling = $validated['is_enabled'] ?? $integration->is_enabled;
        $mergedConfig = array_merge($integration->config ?? [], $validated['config'] ?? []);

        // Enabling requires all mandatory fields to be present; disabling never does.
        if ($enabling && isset(self::REQUIRED_FIELDS[$key])) {
            $missing = array_filter(
                self::REQUIRED_FIELDS[$key],
                fn (string $field) => empty($mergedConfig[$field])
            );

            if ($missing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot enable ' . self::DEFAULTS[$key] . ': missing required field(s): ' . implode(', ', $missing),
                ], 422);
            }
        }

        $integration->update([
            'is_enabled' => $enabling,
            'config' => $mergedConfig,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Integration updated successfully',
            'data' => $integration,
        ]);
    }
}
