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
     * The coloured dot in front of each event's label.
     *
     * Messages are plain text, not cards: Chat doesn't let you select text in
     * a card, and the store's email must be copyable. Plain text can't be
     * coloured, so the colour comes from the emoji.
     *
     * @var array<string, string>
     */
    public const EVENT_EMOJI = [
        'new_installation' => '🟢',
        'reinstallation' => '🔵',
        'uninstallation' => '🔴',
        'plan_change' => '🟠',
    ];

    private const DEFAULT_EMOJI = '⚪';

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

        $details = array_filter([
            'Email' => $installation->email,
            ...$details,
        ], fn ($value) => filled($value));

        $text = $this->heading($event, self::EVENTS[$event] ?? $event) . ' · ' . $this->clean($app->app_name) . "\n"
            . $this->lines([
                'Store' => "{$installation->store_name} ({$installation->store_url})",
                ...$details,
            ]);

        return $spaces->filter(fn (GoogleChatSpace $space) => $this->post($space->webhook_url, $text))->count();
    }

    /**
     * Post a test message, so an admin can check a webhook URL before
     * relying on it.
     */
    public function sendTest(string $webhookUrl): bool
    {
        // Lists every event label, so the test shows what notifications will
        // look like in this space.
        return $this->post($webhookUrl, '*Test message* · Shopify CRM' . "\n"
            . "Google Chat notifications are connected. Events will be labelled like this:\n"
            . implode("\n", array_map(
                fn (string $event, string $label) => $this->heading($event, $label),
                array_keys(self::EVENTS),
                self::EVENTS
            )));
    }

    public static function isValidWebhookUrl(?string $url): bool
    {
        return is_string($url) && str_starts_with($url, self::WEBHOOK_URL_PREFIX);
    }

    private function heading(string $event, string $label): string
    {
        return (self::EVENT_EMOJI[$event] ?? self::DEFAULT_EMOJI) . " *{$label}*";
    }

    /**
     * @param  array<string, string>  $lines
     */
    private function lines(array $lines): string
    {
        return implode("\n", array_map(
            fn (string $label, string $value) => "{$label}: " . $this->clean($value),
            array_keys($lines),
            $lines
        ));
    }

    /**
     * Store names and emails come from the merchant. In Chat text, <...> is
     * markup for links and mentions, so a store named "<users/all>" would
     * ping the whole space. Swap in look-alike brackets that are just text.
     */
    private function clean(string $value): string
    {
        return strtr($value, ['<' => '‹', '>' => '›']);
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
