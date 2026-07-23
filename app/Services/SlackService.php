<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlackService
{
    /**
     * Send a message to the configured Slack channel via incoming webhook.
     *
     * @param string $text
     * @return bool
     */
    public function sendMessage(string $text): bool
    {
        return $this->post(['text' => $text]);
    }

    /**
     * Send a heading + body to Slack, separated by a divider line.
     *
     * @param string $heading
     * @param string $body
     * @return bool
     */
    public function sendSections(string $heading, string $body): bool
    {
        return $this->post([
            'text' => "{$heading}\n{$body}", // fallback for clients that don't render blocks
            'blocks' => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $heading]],
                ['type' => 'divider'],
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $body]],
            ],
        ]);
    }

    /**
     * Post a payload to the configured Slack incoming webhook.
     *
     * @param array $payload
     * @return bool
     */
    protected function post(array $payload): bool
    {
        $integration = Integration::findByKey('slack');

        if (!$integration || !$integration->is_enabled) {
            return false;
        }

        $webhookUrl = $integration->config['webhook_url'] ?? null;

        if (!$webhookUrl) {
            Log::warning('Slack integration enabled but webhook_url is not configured');
            return false;
        }

        try {
            $response = Http::post($webhookUrl, $payload);

            if (!$response->successful()) {
                Log::error('Slack message failed', ['status' => $response->status(), 'body' => $response->body()]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Slack message failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
