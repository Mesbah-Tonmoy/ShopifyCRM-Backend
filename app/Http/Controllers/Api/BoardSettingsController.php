<?php

namespace App\Http\Controllers\Api;

use App\Enums\FeatureRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\FeatureBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BoardSettingsController extends Controller
{
    /**
     * Board configuration for one app, including the credentials the app needs
     * in order to embed it.
     */
    public function show(App $app): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->payload($app, $app->board),
        ]);
    }

    /**
     * Repair endpoint. Boards are provisioned automatically when an app is
     * connected, so this only matters for apps that predate the board feature.
     * Safe to call repeatedly: existing credentials are preserved.
     */
    public function provision(App $app): JsonResponse
    {
        $board = $app->provisionBoard();

        return response()->json([
            'success' => true,
            'message' => 'Board is ready to embed',
            'data' => $this->payload($app->fresh(), $board),
        ]);
    }

    public function update(Request $request, App $app): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:2000'],
            'is_enabled' => ['sometimes', 'boolean'],
            'allow_submissions' => ['sometimes', 'boolean'],
            'allow_voting' => ['sometimes', 'boolean'],
            'allow_comments' => ['sometimes', 'boolean'],
            'require_approval' => ['sometimes', 'boolean'],
            'show_vote_counts' => ['sometimes', 'boolean'],
            'notify_on_status_change' => ['sometimes', 'boolean'],
            'submission_limit_per_day' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'visible_statuses' => ['sometimes', 'array'],
            'visible_statuses.*' => [Rule::in(FeatureRequestStatus::values())],
            'theme' => ['sometimes', 'nullable', 'array'],
        ]);

        $board = $app->board()->firstOrCreate([], ['title' => $app->app_name]);
        $board->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Board settings updated successfully',
            'data' => $this->payload($app, $board->fresh()),
        ]);
    }

    /**
     * Issue a new signing secret. Every app embedding this board must be
     * updated, so the plaintext is returned exactly once, here.
     */
    public function rotateSecret(App $app): JsonResponse
    {
        abort_unless($app->hasBoardCredentials(), 422, 'Set up the board before rotating its secret.');

        $secret = $app->rotateBoardSecret();

        return response()->json([
            'success' => true,
            'message' => 'Secret rotated. Update your app with the new value — the old one no longer works.',
            'data' => [
                'board_secret' => $secret,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(App $app, ?FeatureBoard $board): array
    {
        return [
            'provisioned' => $app->hasBoardCredentials() && $board !== null,
            'app' => [
                'id' => $app->id,
                'app_name' => $app->app_name,
            ],
            'board_slug' => $app->board_slug,
            'board_public_key' => $app->board_public_key,
            'board_url' => $app->board_slug
                ? rtrim((string) config('board.url'), '/') . '/board/' . $app->board_slug
                : null,
            'settings' => $board?->only([
                'title',
                'intro',
                'is_enabled',
                'allow_submissions',
                'allow_voting',
                'allow_comments',
                'require_approval',
                'show_vote_counts',
                'notify_on_status_change',
                'submission_limit_per_day',
                'visible_statuses',
                'theme',
            ]),
            'available_statuses' => FeatureRequestStatus::options(),
        ];
    }
}
