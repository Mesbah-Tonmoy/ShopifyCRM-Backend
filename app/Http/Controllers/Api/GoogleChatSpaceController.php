<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\GoogleChatSpace;
use App\Services\GoogleChatService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Google Chat spaces store events are posted to. Each space has its own
 * webhook, events and apps, so different apps can notify different spaces.
 */
class GoogleChatSpaceController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => GoogleChatSpace::orderBy('name')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $space = GoogleChatSpace::create($this->validated($request));

        return response()->json([
            'success' => true,
            'message' => 'Google Chat space added',
            'data' => $space,
        ], 201);
    }

    public function update(Request $request, GoogleChatSpace $space)
    {
        $space->update($this->validated($request));

        return response()->json([
            'success' => true,
            'message' => 'Google Chat space updated',
            'data' => $space,
        ]);
    }

    public function destroy(GoogleChatSpace $space)
    {
        $space->delete();

        return response()->json([
            'success' => true,
            'message' => 'Google Chat space removed',
        ]);
    }

    /**
     * What a space can subscribe to: the events, and the apps a space can be
     * limited to.
     */
    public function options()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'events' => collect(GoogleChatService::EVENTS)
                    ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                    ->values(),
                'apps' => App::orderBy('app_name')->get(['id', 'app_name']),
            ],
        ]);
    }

    /**
     * Send a test message to a webhook URL, so it can be checked before (or
     * after) it is saved on a space.
     */
    public function test(Request $request)
    {
        $validated = $request->validate([
            'webhook_url' => ['required', 'url', 'starts_with:' . GoogleChatService::WEBHOOK_URL_PREFIX],
        ], $this->messages());

        if (!(new GoogleChatService())->sendTest($validated['webhook_url'])) {
            return response()->json([
                'success' => false,
                'message' => 'Google Chat did not accept the test message. Check the webhook URL.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Test message sent to Google Chat',
        ]);
    }

    /**
     * Validate a space, normalised for storage. A disabled space may be saved
     * half-configured; an enabled one needs at least one event, and at least
     * one app when it is limited to specific apps.
     *
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        $enabled = $request->boolean('is_enabled', true);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'webhook_url' => ['required', 'url', 'starts_with:' . GoogleChatService::WEBHOOK_URL_PREFIX],
            'is_enabled' => ['boolean'],
            'events' => [Rule::requiredIf($enabled), 'array'],
            'events.*' => [Rule::in(array_keys(GoogleChatService::EVENTS))],
            'app_scope' => ['required', Rule::in(['all', 'selected'])],
            'app_ids' => [Rule::requiredIf($enabled && $request->input('app_scope') === 'selected'), 'array'],
            'app_ids.*' => ['integer', 'exists:apps,id'],
        ], $this->messages());

        return [
            'name' => trim($validated['name']),
            'webhook_url' => $validated['webhook_url'],
            'is_enabled' => $enabled,
            'events' => array_values(array_unique($validated['events'] ?? [])),
            'app_scope' => $validated['app_scope'],
            'app_ids' => array_values(array_unique(array_map('intval', $validated['app_ids'] ?? []))),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'webhook_url.starts_with' => 'The webhook URL must be a Google Chat incoming webhook (' . GoogleChatService::WEBHOOK_URL_PREFIX . '...).',
            'events.required' => 'Select at least one event, or turn the space off.',
            'app_ids.required' => 'Select at least one app, or choose "All apps".',
        ];
    }
}
