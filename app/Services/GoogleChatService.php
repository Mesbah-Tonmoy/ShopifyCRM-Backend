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
     * The colour each event's label is shown in. Chat cards can colour text
     * but not backgrounds, so the label is a coloured dot and bold text.
     *
     * Chat silently drops some colours: Google blue (#1A73E8) rendered black,
     * presumably kept for links. Check a new colour with the test message,
     * which shows every label.
     *
     * @var array<string, string>
     */
    public const EVENT_COLORS = [
        'new_installation' => '#1E8E3E', // green
        'reinstallation' => '#4285F4', // light blue
        'uninstallation' => '#D93025', // red
        'plan_change' => '#E37400', // amber
    ];

    private const DEFAULT_COLOR = '#5F6368'; // grey

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

        $label = self::EVENTS[$event] ?? $event;
        $store = "{$installation->store_name} ({$installation->store_url})";

        $payload = $this->card(
            fallback: "{$label} · {$app->app_name}\n" . $this->plainLines(['Store' => $store, ...$details]),
            heading: $this->badge($label, self::EVENT_COLORS[$event] ?? self::DEFAULT_COLOR) . ' · <b>' . e($app->app_name) . '</b>',
            body: implode('<br>', array_filter([
                '<b>Store:</b> ' . e($installation->store_name)
                    . ' (<a href="https://' . e($installation->store_url) . '">' . e($installation->store_url) . '</a>)',
                $this->htmlLines($details),
            ])),
        );

        return $spaces->filter(fn (GoogleChatSpace $space) => $this->post($space->webhook_url, $payload))->count();
    }

    /**
     * Post a test message, so an admin can check a webhook URL before
     * relying on it.
     */
    public function sendTest(string $webhookUrl): bool
    {
        // Sent in the same card format as real events, so the test shows
        // what notifications will look like in this space.
        return $this->post($webhookUrl, $this->card(
            fallback: "Test message · Shopify CRM\nGoogle Chat notifications are connected.",
            heading: $this->badge('Test message', self::EVENT_COLORS['new_installation']) . ' · <b>Shopify CRM</b>',
            body: 'Google Chat notifications are connected. Events will be labelled like this:<br>'
                . implode('<br>', array_map(
                    fn (string $event, string $label) => $this->badge($label, self::EVENT_COLORS[$event] ?? self::DEFAULT_COLOR),
                    array_keys(self::EVENTS),
                    self::EVENTS
                )),
        ));
    }

    public static function isValidWebhookUrl(?string $url): bool
    {
        return is_string($url) && str_starts_with($url, self::WEBHOOK_URL_PREFIX);
    }

    /**
     * A card message: `fallback` is the plain text Chat shows where cards
     * can't render (mobile notifications); `heading` and `body` are card
     * HTML, so every value in them must already be escaped.
     *
     * @return array<string, mixed>
     */
    private function card(string $fallback, string $heading, string $body): array
    {
        return [
            'fallbackText' => $fallback,
            'cardsV2' => [[
                'cardId' => 'event',
                'card' => [
                    'sections' => [[
                        'widgets' => [
                            ['textParagraph' => ['text' => $heading]],
                            ['textParagraph' => ['text' => $body]],
                        ],
                    ]],
                ],
            ]],
        ];
    }

    private function badge(string $label, string $color): string
    {
        return '<font color="' . $color . '"><b>● ' . e($label) . '</b></font>';
    }

    /**
     * @param  array<string, string>  $lines
     */
    private function plainLines(array $lines): string
    {
        return implode("\n", array_map(fn (string $label, string $value) => "{$label}: {$value}", array_keys($lines), $lines));
    }

    /**
     * @param  array<string, string>  $lines
     */
    private function htmlLines(array $lines): string
    {
        return implode('<br>', array_map(fn (string $label, string $value) => '<b>' . e($label) . ':</b> ' . e($value), array_keys($lines), $lines));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(?string $webhookUrl, array $payload): bool
    {
        if (!self::isValidWebhookUrl($webhookUrl)) {
            Log::warning('Google Chat space has no valid webhook_url');
            return false;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)->post($webhookUrl, $payload);

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
