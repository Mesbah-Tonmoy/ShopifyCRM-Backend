<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\App as ConnectedApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SMTP services for a connected app's platform sender.
 *
 * Deliberately a proxy and not a local table. The app itself is what sends the
 * mail and what must keep working when the CRM is down or unreachable, so the
 * configuration lives in the app's own database, next to the send path. Holding
 * a second copy here would only give the two a chance to disagree.
 *
 * Every call goes to `{app_url}/api/smtp-providers` with the shared webhook
 * secret, the same channel `AppController::pushPlans` already uses. Passwords
 * are write-only: they go out on save and come back only as a mask.
 */
class SmtpProviderController extends Controller
{
    /**
     * Fields the app accepts on a provider. Anything else is dropped rather
     * than forwarded, so a stray key in the payload cannot reach the app.
     */
    protected const PROVIDER_FIELDS = [
        'key', 'name', 'host', 'port', 'secure', 'username', 'password',
        'fromEmail', 'fromName', 'replyTo', 'configurationSet', 'isEnabled', 'isActive',
        'priority', 'extra',
        'awsRegion', 'awsAccessKeyId', 'awsSecretAccessKey',
        'sesIdentityArn', 'sesConfigurationSetArn',
    ];

    /**
     * List the app's SMTP providers and which one is currently live.
     */
    public function index(ConnectedApp $app)
    {
        return $this->forward($app, 'get');
    }

    /**
     * Create or update a provider, keyed on its slug.
     *
     * `password` is omitted from the payload when the client did not send one,
     * which is how an edit form that only ever received a mask says "leave the
     * stored password alone". An explicit empty string clears it.
     */
    public function store(Request $request, ConnectedApp $app)
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'name' => 'nullable|string|max:191',
            'host' => 'required|string|max:191',
            'port' => 'nullable|integer|min:1|max:65535',
            'secure' => 'nullable|boolean',
            'username' => 'nullable|string|max:191',
            'password' => 'nullable|string|max:1000',
            'fromEmail' => 'nullable|email|max:191',
            'fromName' => 'nullable|string|max:191',
            'replyTo' => 'nullable|email|max:191',
            'configurationSet' => 'nullable|string|max:191',
            'isEnabled' => 'nullable|boolean',
            'isActive' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0|max:9999',
            // SES tenant management needs API credentials; SMTP credentials
            // cannot call the SES API.
            'awsRegion' => 'nullable|string|max:64',
            'awsAccessKeyId' => 'nullable|string|max:191',
            'awsSecretAccessKey' => 'nullable|string|max:1000',
            'sesIdentityArn' => 'nullable|string|max:512',
            'sesConfigurationSetArn' => 'nullable|string|max:512',
            'extra' => 'nullable|array',
        ], [
            'key.regex' => 'The key must be lowercase letters, digits, hyphens or underscores.',
        ]);

        $provider = array_intersect_key($validated, array_flip(self::PROVIDER_FIELDS));

        return $this->forward($app, 'post', [
            'intent' => 'upsert',
            'provider' => $provider,
        ]);
    }

    /**
     * Make one provider the app's active platform sender.
     */
    public function activate(Request $request, ConnectedApp $app)
    {
        $validated = $request->validate(['id' => 'required|string|max:64']);

        return $this->forward($app, 'post', [
            'intent' => 'activate',
            'id' => $validated['id'],
        ]);
    }

    /**
     * Open and authenticate a connection without sending anything, so a bad
     * host or credential is distinguishable from a bad recipient.
     */
    public function test(Request $request, ConnectedApp $app)
    {
        $validated = $request->validate(['id' => 'required|string|max:64']);

        return $this->forward($app, 'post', [
            'intent' => 'test',
            'id' => $validated['id'],
        ]);
    }

    /**
     * Rewrite every stored secret under the app's current encryption key.
     *
     * The second step of a key rotation: put the new key in the app's
     * SMTP_ENCRYPTION_KEY, move the old one to SMTP_ENCRYPTION_KEY_PREVIOUS,
     * deploy, then run this. Until it reports nothing stale, the previous key is
     * still load-bearing and removing it would make those credentials
     * unreadable.
     */
    public function reencrypt(ConnectedApp $app)
    {
        return $this->forward($app, 'post', ['intent' => 'reencrypt']);
    }

    /**
     * Delete a provider. Removing the active one drops the app back to its
     * environment SMTP config, which still sends; the app says so in its
     * response message.
     */
    public function destroy(ConnectedApp $app, string $id)
    {
        return $this->forward($app, 'post', [
            'intent' => 'delete',
            'id' => $id,
        ]);
    }

    /**
     * Single exit point to the app.
     *
     * The app's own status code and body are passed through, so a validation
     * failure there reads as a validation failure here instead of a generic
     * 500. Only transport problems — unreachable host, timeout — are turned
     * into a message of our own.
     */
    protected function forward(ConnectedApp $app, string $method, array $payload = [])
    {
        $secret = config('webhook.secret');

        if (empty($secret)) {
            return response()->json([
                'success' => false,
                'message' => 'WEBHOOK_SECRET is not configured on the CRM.',
            ], 500);
        }

        $url = rtrim($app->app_url, '/') . '/api/smtp-providers';

        try {
            $request = Http::timeout(config('webhook.timeout', 30))->withToken($secret);

            $response = $method === 'get'
                ? $request->get($url)
                : $request->post($url, $payload);

            $body = $response->json();

            if (!is_array($body)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The app returned an unreadable response (HTTP ' . $response->status() . ').',
                ], 502);
            }

            // The app reports failures as `error`; the CRM frontend reads `message`.
            if (isset($body['error']) && !isset($body['message'])) {
                $body['message'] = $body['error'];
            }

            return response()->json($body, $response->successful() ? 200 : $response->status());

        } catch (\Exception $e) {
            Log::channel('stderr')->error('SMTP provider proxy error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Could not reach ' . $app->app_name . ': ' . $e->getMessage(),
            ], 502);
        }
    }
}
