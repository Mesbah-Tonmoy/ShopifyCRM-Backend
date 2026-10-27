<?php

namespace App\Services;

use App\Models\App;
use App\Models\GoogleChatSpace;
use App\Models\Installation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleChatService
{
    /**
     * Events a Google Chat space can subscribe to, keyed by the value stored
     * on the space. Add an entry here (and a notify() call where
     * the event happens) to offer a new one; the settings page reads this list.
     *
     * @var array<string, string>
     */
    public const EVENTS = [
        'new_installation' => 'New installation',
        'reinstallation' => 'Reinstallation',
        'uninstallation' => 'Uninstallation',
        'plan_change' => 'Plan change',
    ];

    /**
     * Only Google Chat incoming webhooks are accepted, so the URL field can't
     * be used to make this server call arbitrary hosts.
     */
    public const WEBHOOK_URL_PREFIX = 'https://chat.googleapis.com/';

    /**
     * Seconds to wait on Google before giving up, so a slow Chat API can't
     * hold up the Shopify webhook that triggered the notification.
     */
    private const TIMEOUT = 5;

    /**
     * Post an event to every enabled space that subscribes to it for this
     * app. Returns how many spaces it was delivered to.
     *
     * @param  array<string, string|null>  $details  extra "label => value" lines for the message
     */
    public function notify(string $event, App $app, Installation $installation, array $details = []): int
    {
        $spaces = GoogleChatSpace::enabled()->get()
            ->filter(fn (GoogleChatSpace $space) => $space->wants($event, $app))
            // Two spaces sharing a webhook are the same Chat space: post once.
            ->unique('webhook_url');

        if ($spaces->isEmpty()) {
            return 0;
        }

        $lines = array_filter([
            'Store' => "{$installation->store_name} ({$installation->store_url})",
            'Email' => $installation->email,
            ...$details,
        ], fn ($value) => filled($value));

        $text = '*' . (self::EVENTS[$event] ?? $event) . "* · {$app->app_name}\n"
            . implode("\n", array_map(
                fn (string $label, string $value) => "{$label}: {$value}",
                array_keys($lines),
                $lines
            ));

        return $spaces->filter(fn (GoogleChatSpace $space) => $this->post($space->webhook_url, $text))->count();
    }

    /**
     * Post a test message, so an admin can check a webhook URL before
     * relying on it.
     */
    public function sendTest(string $webhookUrl): bool
    {
        return $this->post($webhookUrl, '*Test message* · Shopify CRM' . "\n" . 'Google Chat notifications are connected.');
    }

    public static function isValidWebhookUrl(?string $url): bool
    {
        return is_string($url) && str_starts_with($url, self::WEBHOOK_URL_PREFIX);
    }

    private function post(?string $webhookUrl, string $text): bool
    {
        if (!self::isValidWebhookUrl($webhookUrl)) {
            Log::warning('Google Chat space has no valid webhook_url');
            return false;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)->post($webhookUrl, ['text' => $text]);

            if (!$response->successful()) {
                Log::error('Google Chat message failed', ['status' => $response->status(), 'body' => $response->body()]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Google Chat message failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
