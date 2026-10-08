<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\App as ConnectedApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SES tenants for a connected app: a dedicated tenant per premium store, one
 * shared free-pool tenant for every free-plan store, per region.
 *
 * A proxy, for the same reason as SmtpProviderController — the app owns the
 * table and does the sending, so a second copy here could only disagree with
 * it. Every call goes to `{app_url}/api/ses-tenants` with the shared webhook
 * secret.
 *
 * Pausing is the operationally sensitive part: it stops a store's email
 * immediately, in every region. The app records who asked and why, so this
 * controller always forwards the acting user.
 */
class SesTenantController extends Controller
{
    /**
     * List tenants grouped by store, plus how many stores still lack one.
     */
    public function index(Request $request, ConnectedApp $app)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:191',
            'limit' => 'nullable|integer|min:1|max:500',
            'skip' => 'nullable|integer|min:0',
            // Which region the "stores without tenants" count describes. Omitted
            // means the active one, matching what provisioning would do.
            'providerKey' => 'nullable|string|max:64',
            'allProviders' => 'nullable|boolean',
            // Which rows to list: premium stores, free-plan stores, or the
            // shared pool/platform tenants. Omitted lists all of them.
            'type' => 'nullable|in:dedicated,free,system',
            // Table filters, applied by the app so paging stays correct.
            'region' => 'nullable|string|max:64',
            'status' => 'nullable|in:paused,broken,healthy,risky',
            'sort' => 'nullable|in:shop,bounce,complaint,sent',
        ]);

        return $this->forward($app, 'get', [], $validated);
    }

    /**
     * Backfill tenants for stores that predate the feature.
     *
     * Paged on purpose: SES throttles bursts, and the app provisions
     * sequentially. The UI calls this repeatedly with a rising `skip`.
     *
     * Also the first act of a region migration: pass the target `providerKey`
     * to fill a region the app is not yet sending from.
     */
    public function provisionAll(Request $request, ConnectedApp $app)
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
            'skip' => 'nullable|integer|min:0',
            'providerKey' => 'nullable|string|max:64',
            'allProviders' => 'nullable|boolean',
        ]);

        return $this->forward($app, 'post', [
            'intent' => 'provisionAll',
            'limit' => $validated['limit'] ?? 25,
            'skip' => $validated['skip'] ?? 0,
            'providerKey' => $validated['providerKey'] ?? null,
            'allProviders' => $validated['allProviders'] ?? false,
        ]);
    }

    /**
     * Create or repair one store's tenant.
     *
     * Defaults to the active region. `providerKey` targets another one, which
     * is how a single store is moved or pre-staged.
     */
    public function provisionShop(Request $request, ConnectedApp $app)
    {
        $validated = $request->validate([
            'shop' => 'required|string|max:191',
            'rotate' => 'nullable|boolean',
            'providerKey' => 'nullable|string|max:64',
            'allProviders' => 'nullable|boolean',
        ]);

        return $this->forward($app, 'post', [
            'intent' => 'provisionShop',
            'shop' => $validated['shop'],
            'rotate' => $validated['rotate'] ?? false,
            'providerKey' => $validated['providerKey'] ?? null,
            'allProviders' => $validated['allProviders'] ?? false,
        ]);
    }

    /**
     * Permanently delete a store's tenants.
     *
     * `rotate` on provisionShop is the softer sibling of this: it replaces a
     * tenant in place. Deletion is for when the store should have none at all,
     * and it stops the per-tenant AWS charge.
     */
    public function destroy(Request $request, ConnectedApp $app)
    {
        $validated = $request->validate([
            'shop' => 'required|string|max:191',
            'providerKey' => 'nullable|string|max:64',
            'force' => 'nullable|boolean',
        ]);

        return $this->forward($app, 'post', [
            'intent' => 'delete',
            'shop' => $validated['shop'],
            'providerKey' => $validated['providerKey'] ?? null,
            'force' => $validated['force'] ?? false,
        ]);
    }

    /**
     * Stop a store's sending in every region.
     *
     * A reason is mandatory. These pauses are reviewed later — often by someone
     * else, often weeks on — and "paused, no reason recorded" is not reviewable.
     */
    public function pause(Request $request, ConnectedApp $app)
    {
        $validated = $request->validate([
            'shop' => 'required|string|max:191',
            'reason' => 'required|string|max:1000',
        ]);

        return $this->forward($app, 'post', [
            'intent' => 'pause',
            'shop' => $validated['shop'],
            'reason' => $validated['reason'],
            'by' => $request->user()?->email ?? 'crm',
        ]);
    }

    /**
     * Resume a store's sending in every region.
     */
    public function resume(Request $request, ConnectedApp $app)
    {
        $validated = $request->validate(['shop' => 'required|string|max:191']);

        return $this->forward($app, 'post', [
            'intent' => 'resume',
            'shop' => $validated['shop'],
            'by' => $request->user()?->email ?? 'crm',
        ]);
    }

    /**
     * The regions tenants can live in, which one is live, and how full each is.
     */
    public function regions(Request $request, ConnectedApp $app)
    {
        return $this->forward($app, 'post', ['intent' => 'regions']);
    }

    /**
     * What a region move still needs before the active provider can be flipped.
     *
     * Read-only and deliberately separate from performing any of it: an
     * operator should be able to look at the plan, and at what this app cannot
     * do for them, without starting anything.
     */
    public function migrationStatus(Request $request, ConnectedApp $app)
    {
        $validated = $request->validate([
            'from' => 'required|string|max:64',
            'to' => 'required|string|max:64|different:from',
        ]);

        return $this->forward($app, 'post', [
            'intent' => 'migrationStatus',
            'from' => $validated['from'],
            'to' => $validated['to'],
        ]);
    }

    /**
     * Delete every tenant in one region, a page at a time.
     *
     * The last act of a migration. The app refuses the region it is currently
     * sending from unless `allowActive` is passed, because emptying it stops
     * all mail - SES rejects untenanted sends under a TENANT suppression scope.
     */
    public function destroyRegion(Request $request, ConnectedApp $app)
    {
        $validated = $request->validate([
            'providerKey' => 'required|string|max:64',
            'limit' => 'nullable|integer|min:1|max:100',
            'force' => 'nullable|boolean',
            'allowActive' => 'nullable|boolean',
        ]);

        return $this->forward($app, 'post', [
            'intent' => 'deleteRegion',
            'providerKey' => $validated['providerKey'],
            'limit' => $validated['limit'] ?? 25,
            'force' => $validated['force'] ?? false,
            'allowActive' => $validated['allowActive'] ?? false,
        ]);
    }

    /**
     * Pull AWS-side status back in, for the whole table or one store.
     *
     * SES pauses tenants on its own through reputation policies, so our view can
     * fall behind. The EventBridge webhook reports those promptly; this covers
     * events that never arrive.
     */
    public function sync(Request $request, ConnectedApp $app)
    {
        $validated = $request->validate(['shop' => 'nullable|string|max:191']);

        return $this->forward($app, 'post', [
            'intent' => 'sync',
            'shop' => $validated['shop'] ?? null,
        ]);
    }

    /**
     * Re-check one store's plan against Shopify and move its tenant to match:
     * a dedicated tenant for premium, the free pool otherwise. The daily check
     * does this for every store; this is for not waiting until then.
     */
    public function reconcile(Request $request, ConnectedApp $app)
    {
        $validated = $request->validate(['shop' => 'required|string|max:191']);

        return $this->forward($app, 'post', [
            'intent' => 'reconcile',
            'shop' => $validated['shop'],
        ]);
    }

    /**
     * The daily tenant check's settings and its recent runs.
     */
    public function monitor(Request $request, ConnectedApp $app)
    {
        return $this->forward($app, 'post', ['intent' => 'monitor']);
    }

    /**
     * Save when the daily check runs and who it emails. The app validates the
     * time zone and addresses too; this only bounds the shape.
     */
    public function saveMonitor(Request $request, ConnectedApp $app)
    {
        $validated = $request->validate([
            'enabled' => 'sometimes|boolean',
            'runAt' => ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'timezone' => 'sometimes|string|max:64',
            'recipients' => 'sometimes|array|max:20',
            'recipients.*' => 'email|max:191',
            'realtimeAlerts' => 'sometimes|boolean',
            'emailWhenUnchanged' => 'sometimes|boolean',
            // Per-store bounce/complaint limits for the hourly check (percentages).
            'reputation' => 'sometimes|array',
            'reputation.enabled' => 'sometimes|boolean',
            'reputation.minSends' => 'sometimes|integer|min:1|max:1000000',
            'reputation.warnBouncePct' => 'sometimes|numeric|min:0.1|max:50',
            'reputation.pauseBouncePct' => 'sometimes|numeric|min:0.1|max:50',
            'reputation.warnComplaintPct' => 'sometimes|numeric|min:0.01|max:5',
            'reputation.pauseComplaintPct' => 'sometimes|numeric|min:0.01|max:5',
        ]);

        return $this->forward($app, 'post', [
            'intent' => 'saveMonitor',
            'settings' => $validated,
        ]);
    }

    /**
     * Run the daily check now. The app starts it in the background and
     * answers at once — it walks every store, which outlasts this proxy's
     * timeout — so the page polls `monitor` for the outcome.
     */
    public function runCheck(Request $request, ConnectedApp $app)
    {
        return $this->forward($app, 'post', ['intent' => 'runCheck']);
    }

    /**
     * Single exit point to the app. Passes the app's own status and body
     * through so a validation failure there does not read as a 500 here.
     */
    protected function forward(ConnectedApp $app, string $method, array $payload = [], array $query = [])
    {
        $secret = config('webhook.secret');

        if (empty($secret)) {
            return response()->json([
                'success' => false,
                'message' => 'WEBHOOK_SECRET is not configured on the CRM.',
            ], 500);
        }

        $url = rtrim($app->app_url, '/') . '/api/ses-tenants';

        try {
            // Provisioning walks stores one at a time against the SES API, so it
            // needs considerably longer than a normal request.
            $timeout = $method === 'post' ? 120 : config('webhook.timeout', 30);

            $request = Http::timeout($timeout)->withToken($secret);

            $response = $method === 'get'
                ? $request->get($url, array_filter($query, fn ($v) => $v !== null))
                : $request->post($url, $payload);

            $body = $response->json();

            if (!is_array($body)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The app returned an unreadable response (HTTP ' . $response->status() . ').',
                ], 502);
            }

            if (isset($body['error']) && !isset($body['message'])) {
                $body['message'] = $body['error'];
            }

            return response()->json($body, $response->successful() ? 200 : $response->status());

        } catch (\Exception $e) {
            Log::channel('stderr')->error('SES tenant proxy error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Could not reach ' . $app->app_name . ': ' . $e->getMessage(),
            ], 502);
        }
    }
}
