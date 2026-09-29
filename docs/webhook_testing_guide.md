# Webhook Implementation Guide

## Overview

This guide explains how to implement webhooks in your Laravel CRM to receive real-time updates from your Shopify apps for:
- ✅ New installations
- ✅ Uninstallations
- ✅ Plan changes

---

## Laravel CRM Setup

### 1. Create WebhookController

Place the `WebhookController.php` in:
```
app/Http/Controllers/Api/WebhookController.php
```

### 2. Add Webhook Routes

Add these routes to your `routes/api.php`:

```php
use App\Http\Controllers\Api\WebhookController;

// Webhook routes (public, no auth required)
Route::prefix('webhooks')->group(function () {
    Route::post('/install', [WebhookController::class, 'install']);
    Route::post('/uninstall', [WebhookController::class, 'uninstall']);
    Route::post('/plan-change', [WebhookController::class, 'planChange']);
});
```

### 3. Create Webhook Config

Create `config/webhook.php`:

```php
<?php

return [
    'secret' => env('WEBHOOK_SECRET', ''),
    'timeout' => env('WEBHOOK_TIMEOUT', 30),
];
```

### 4. Update .env

Add to your Laravel CRM `.env`:

```env
WEBHOOK_SECRET=your-super-secret-key-here-make-it-random-and-long
```

**Generate a secure secret:**
```bash
php artisan tinker
>>> Str::random(64)
```

### 5. Optional: Add Webhook Security Middleware

For production, add signature verification:

**Create middleware:**
```bash
php artisan make:middleware VerifyWebhookSignature
```

**Place the provided middleware code in:**
```
app/Http/Middleware/VerifyWebhookSignature.php
```

**Register in `app/Http/Kernel.php`:**
```php
protected $middlewareAliases = [
    // ... other middleware
    'verify.webhook' => \App\Http\Middleware\VerifyWebhookSignature::class,
];
```

**Update routes to use middleware:**
```php
Route::prefix('webhooks')->middleware('verify.webhook')->group(function () {
    Route::post('/install', [WebhookController::class, 'install']);
    Route::post('/uninstall', [WebhookController::class, 'uninstall']);
    Route::post('/plan-change', [WebhookController::class, 'planChange']);
});
```

---

## Shopify App Setup

### 1. Create WebhookService

In your Shopify app, create:
```
app/Services/WebhookService.php
```

Use the provided `WebhookService` code.

### 2. Add CRM Configuration

Create `config/crm.php` in your Shopify app:

```php
<?php

return [
    'url' => env('CRM_URL', 'http://localhost:8000'),
    'webhook_secret' => env('CRM_WEBHOOK_SECRET', ''),
];
```

### 3. Update Shopify App .env

Add these to your Shopify app's `.env`:

```env
CRM_URL=http://localhost:8000
CRM_WEBHOOK_SECRET=your-super-secret-key-here-make-it-random-and-long
```

**Important:** Use the SAME secret in both Laravel CRM and Shopify app!

### 4. Implement Webhook Calls

Use the service in your Shopify app controllers:

**On Installation:**
```php
use App\Services\WebhookService;

public function afterAuth($shop)
{
    $webhookService = app(WebhookService::class);
    $webhookService->sendInstallation($shop);
}
```

**On Uninstallation:**
```php
public function handleUninstall($shopDomain)
{
    $shop = Shop::where('shop_domain', $shopDomain)->first();
    
    $webhookService = app(WebhookService::class);
    $webhookService->sendUninstallation($shop);
}
```

**On Plan Change:**
```php
public function upgradePlan($shopDomain, $newPlan)
{
    $shop = Shop::where('shop_domain', $shopDomain)->first();
    $shop->update(['app_plan' => $newPlan]);
    
    $webhookService = app(WebhookService::class);
    $webhookService->sendPlanChange($shop, $newPlan, now(), null);
}
```

---

## Webhook Endpoints

### 1. Installation Webhook

**Endpoint:** `POST /api/webhooks/install`

**Request Body:**
```json
{
  "app_url": "http://localhost:8001",
  "shop_domain": "example-store.myshopify.com",
  "shopify_shop_id": "gid://shopify/Shop/123456",
  "name": "Example Store",
  "email": "owner@example.com",
  "shop_owner_name": "John Doe",
  "currency_code": "USD",
  "primary_domain": "https://example-store.myshopify.com",
  "shopify_plan": "Basic",
  "is_shopify_plus": false,
  "timezone": "America/New_York",
  "app_plan": "Premium",
  "plan_started_at": "2025-01-20T10:00:00.000Z",
  "plan_expires_at": null
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Installation recorded successfully",
  "data": {
    "id": 1,
    "app_id": 1,
    "store_name": "Example Store",
    "store_url": "example-store.myshopify.com",
    "email": "owner@example.com",
    "is_active": true,
    "install_count": 1,
    "created_at": "2025-01-20T10:00:00.000000Z",
    "updated_at": "2025-01-20T10:00:00.000000Z"
  }
}
```

### 2. Uninstallation Webhook

**Endpoint:** `POST /api/webhooks/uninstall`

**Request Body:**
```json
{
  "app_url": "http://localhost:8001",
  "shop_domain": "example-store.myshopify.com"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Uninstallation recorded successfully",
  "data": {
    "id": 1,
    "is_active": false,
    "updated_at": "2025-01-20T11:00:00.000000Z"
  }
}
```

### 3. Plan Change Webhook

**Endpoint:** `POST /api/webhooks/plan-change`

**Request Body:**
```json
{
  "app_url": "http://localhost:8001",
  "shop_domain": "example-store.myshopify.com",
  "app_plan": "Enterprise",
  "plan_started_at": "2025-01-20T12:00:00.000Z",
  "plan_expires_at": "2026-01-20T12:00:00.000Z"
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Plan change recorded successfully",
  "data": {
    "id": 1,
    "app_plan": "Enterprise",
    "plan_started_at": "2025-01-20T12:00:00.000000Z",
    "plan_expires_at": "2026-01-20T12:00:00.000000Z",
    "updated_at": "2025-01-20T12:00:00.000000Z"
  }
}
```

### 4. Reachability Check

**Endpoint:** `GET /api/webhooks/ping`

Confirms a base URL actually reaches this API before webhooks are pointed at
it. Requires no payload and no signature.

**Response (200):**
```json
{
  "success": true,
  "message": "CRM webhook endpoints are reachable",
  "received_over": "https",
  "endpoints": {
    "install": "POST https://your-crm.com/api/webhooks/install",
    "uninstall": "POST https://your-crm.com/api/webhooks/uninstall",
    "plan-change": "POST https://your-crm.com/api/webhooks/plan-change"
  }
}
```

---

## Testing Webhooks

### Method 1: Using cURL

**Test Installation:**
```bash
curl -X POST http://localhost:8000/api/webhooks/install \
  -H "Content-Type: application/json" \
  -d '{
    "app_url": "http://localhost:8001",
    "shop_domain": "test-store.myshopify.com",
    "name": "Test Store",
    "email": "test@example.com",
    "currency_code": "USD",
    "shopify_plan": "Basic",
    "app_plan": "Free"
  }'
```

**Test Uninstallation:**
```bash
curl -X POST http://localhost:8000/api/webhooks/uninstall \
  -H "Content-Type: application/json" \
  -d '{
    "app_url": "http://localhost:8001",
    "shop_domain": "test-store.myshopify.com"
  }'
```

**Test Plan Change:**
```bash
curl -X POST http://localhost:8000/api/webhooks/plan-change \
  -H "Content-Type: application/json" \
  -d '{
    "app_url": "http://localhost:8001",
    "shop_domain": "test-store.myshopify.com",
    "app_plan": "Premium",
    "plan_started_at": "2025-01-20T10:00:00.000Z"
  }'
```

### Method 2: Using Postman

1. Create new POST request
2. URL: `http://localhost:8000/api/webhooks/install`
3. Headers: `Content-Type: application/json`
4. Body (raw JSON): Use the example request bodies above
5. Send and verify response

### Method 3: Test with Signature Verification

If using the security middleware:

```bash
# Generate signature
SECRET="your-webhook-secret"
PAYLOAD='{"app_url":"http://localhost:8001","shop_domain":"test.myshopify.com","name":"Test","email":"test@test.com"}'
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')

# Send request with signature
curl -X POST http://localhost:8000/api/webhooks/install \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Signature: $SIGNATURE" \
  -d "$PAYLOAD"
```

### Method 4: Create a Test Route in Shopify App

Create a test route in your Shopify app for manual testing:

```php
// routes/web.php or api.php
Route::get('/test-webhook/{type}', function ($type) {
    $webhookService = app(\App\Services\WebhookService::class);
    $shop = \App\Models\Shop::first(); // Get any shop for testing
    
    if (!$shop) {
        return 'No shop found for testing';
    }
    
    switch ($type) {
        case 'install':
            $result = $webhookService->sendInstallation($shop);
            break;
        case 'uninstall':
            $result = $webhookService->sendUninstallation($shop);
            break;
        case 'plan-change':
            $result = $webhookService->sendPlanChange($shop, 'Premium');
            break;
        default:
            return 'Invalid type. Use: install, uninstall, or plan-change';
    }
    
    return $result ? 'Webhook sent successfully' : 'Webhook failed';
});
```

Then visit:
- `http://localhost:8001/test-webhook/install`
- `http://localhost:8001/test-webhook/uninstall`
- `http://localhost:8001/test-webhook/plan-change`

---

## Monitoring & Debugging

### 1. Check Laravel Logs

Webhooks and emails have their own rotating channels (30 days), mirrored to
stderr so they also show up in `docker compose logs app`:

```bash
tail -f storage/logs/webhooks-$(date +%F).log
```

```bash
tail -f storage/logs/emails-$(date +%F).log
```

Every inbound webhook is logged twice - once on arrival (`Webhook request
received`, with method, URL, scheme, IP, proxy headers and payload) and once on
the way out (`Webhook request handled` / `Webhook request failed`, with the
status code and duration). Both carry the same `request_id`, and so does every
line logged while handling it, so one request can be followed end to end:

```bash
grep <request_id> storage/logs/webhooks-$(date +%F).log
```

Look for:
- `Install webhook validated` / `Uninstall webhook validated` / `Plan change webhook validated`
- `Installation created/updated` - with `install_type`: `new`, `reinstall` or `duplicate`
- `Install email sent` / `Install email skipped` (the skip line says why)
- `Webhook rejected: ...` - a delivery we refused, and the reason
- `Webhook delivery did not match any route` - a delivery that hit the wrong path

On the email channel:
- `Sending email` -> `Email sent successfully` (with mailer, subject, from, duration)
- `Failed to send email` - with the exception class, message and source location
- `Email not sent: ...` - no template active for the type, or no recipient address

### 2. Enable Query Logging

Add to your webhook controller temporarily:

```php
use Illuminate\Support\Facades\DB;

public function install(Request $request)
{
    DB::enableQueryLog();
    
    // ... your code ...
    
    Log::info('Queries executed', DB::getQueryLog());
}
```

### 3. Check Database

Verify data is being saved:

```bash
php artisan tinker
```

```php
// Check apps
App\Models\App::all();

// Check installations
App\Models\Installation::all();

// Check specific installation
App\Models\Installation::where('store_url', 'test-store.myshopify.com')->first();

// Check install count
App\Models\Installation::where('store_url', 'test-store.myshopify.com')->value('install_count');
```

### 4. Test Error Scenarios

**Missing app_url:**
```bash
curl -X POST http://localhost:8000/api/webhooks/install \
  -H "Content-Type: application/json" \
  -d '{"shop_domain": "test.myshopify.com"}'
```

**App not found:**
```bash
curl -X POST http://localhost:8000/api/webhooks/install \
  -H "Content-Type: application/json" \
  -d '{
    "app_url": "http://nonexistent-app.com",
    "shop_domain": "test.myshopify.com",
    "name": "Test",
    "email": "test@test.com"
  }'
```

---

## Production Considerations

### 1. Use HTTPS

In production, always use HTTPS:
```env
# Laravel CRM
APP_URL=https://your-crm.com

# Shopify App
CRM_URL=https://your-crm.com
```

### 2. Enable Signature Verification

Always use the `VerifyWebhookSignature` middleware in production.

### 3. Rate Limiting

Add rate limiting to webhook routes:

```php
Route::prefix('webhooks')
    ->middleware(['verify.webhook', 'throttle:60,1'])
    ->group(function () {
        Route::post('/install', [WebhookController::class, 'install']);
        Route::post('/uninstall', [WebhookController::class, 'uninstall']);
        Route::post('/plan-change', [WebhookController::class, 'planChange']);
    });
```

### 4. Queue Processing

For better performance, process webhooks asynchronously:

```php
use Illuminate\Support\Facades\Queue;

public function install(Request $request)
{
    $data = $request->all();
    
    Queue::push(function () use ($data) {
        // Process webhook data
        $this->processInstallation($data);
    });
    
    return response()->json([
        'success' => true,
        'message' => 'Webhook received and queued',
    ], 202);
}
```

### 5. Retry Failed Webhooks

In your Shopify app, add retry logic:

```php
protected function sendWebhook(string $endpoint, array $data, int $attempt = 1): bool
{
    try {
        $payload = json_encode($data);
        $signature = $this->generateSignature($payload);

        $response = Http::timeout(30)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Webhook-Signature' => $signature,
            ])
            ->post($this->crmUrl . $endpoint, $data);

        if ($response->successful()) {
            return true;
        }

        // Retry logic
        if ($attempt < 3) {
            sleep(5 * $attempt); // Exponential backoff
            return $this->sendWebhook($endpoint, $data, $attempt + 1);
        }

        return false;
    } catch (\Exception $e) {
        if ($attempt < 3) {
            sleep(5 * $attempt);
            return $this->sendWebhook($endpoint, $data, $attempt + 1);
        }
        return false;
    }
}
```

### 6. Webhook Logging Table

Create a table to log all webhook attempts:

```bash
php artisan make:migration create_webhook_logs_table
```

```php
Schema::create('webhook_logs', function (Blueprint $table) {
    $table->id();
    $table->string('type'); // install, uninstall, plan-change
    $table->string('app_url');
    $table->string('shop_domain');
    $table->json('payload');
    $table->string('status'); // success, failed
    $table->text('error_message')->nullable();
    $table->timestamps();
});
```

Update webhook controller:

```php
use App\Models\WebhookLog;

public function install(Request $request)
{
    $log = WebhookLog::create([
        'type' => 'install',
        'app_url' => $request->app_url,
        'shop_domain' => $request->shop_domain,
        'payload' => $request->all(),
        'status' => 'processing',
    ]);

    try {
        // ... process webhook ...
        
        $log->update(['status' => 'success']);
        
        return response()->json(['success' => true]);
    } catch (\Exception $e) {
        $log->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
        ]);
        
        throw $e;
    }
}
```

---

## Common Issues & Solutions

### Issue 1: "App not found"

**Cause:** The app hasn't been connected via the "Connect App" feature.

**Solution:** 
1. Go to Apps page in your CRM
2. Click "Connect App"
3. Enter the Shopify app URL
4. Then try sending webhooks again

### Issue 2: Webhooks not received

**Causes:**
- CRM not running
- Wrong URL in Shopify app config
- Firewall blocking requests
- CORS issues

**Solutions:**
```bash
# Check if CRM is accessible
curl http://localhost:8000/api/webhooks/install

# Check Shopify app config
cat .env | grep CRM_URL

# Test from Shopify app server
curl -X POST http://your-crm-url/api/webhooks/install \
  -H "Content-Type: application/json" \
  -d '{"test": "data"}'
```

### Issue 3: Signature verification fails

**Causes:**
- Different secrets in CRM and Shopify app
- Payload modified during transmission
- Wrong signature generation

**Solutions:**
```bash
# Verify secrets match
# In CRM: php artisan tinker >>> config('webhook.secret')
# In Shopify app: php artisan tinker >>> config('crm.webhook_secret')

# Test without middleware first
# Remove middleware from routes temporarily
```

### Issue 4: Duplicate installations

**Cause:** Webhook sent multiple times for same installation.

**Solution:** Already handled! The code uses `updateOrCreate` which prevents duplicates based on `app_id` + `store_url`.

### Issue 5: Install count not incrementing

**Cause:** The increment logic in the controller.

`install_count` counts *real* installs: it starts at 1 when the store is first
recorded and is incremented only when a store that was marked inactive
installs again. Repeated deliveries of the same install webhook deliberately
leave it alone (they used to inflate it).

**Verify:**
```php
// In webhook controller
if ($isReinstall) {           // was inactive before this webhook
    $installation->increment('install_count');
}
```

### Issue 6: Webhook fails with `405 Method Not Allowed`

**What the app side sees:** `webhook failed, status 405`, no installation in the CRM
(a manual **Resync** on the Apps page pulls the store in afterwards).

**How to tell where the 405 came from.** Every request that reaches
`/api/webhooks/*` is now logged. On the CRM:

```bash
tail -f storage/logs/webhooks-$(date +%F).log
```

- **The request appears in the log** with `Webhook rejected: method not allowed` →
  it reached Laravel with the wrong HTTP verb. The log line carries the
  `expected_method`, the `hint`, and `body_bytes`. `method: GET` with
  `body_bytes: 0` means a **redirect rewrote the POST**: Guzzle (and therefore
  Laravel's `Http` client) turns a 301/302 into a GET and drops the body. The
  usual hops are `http` → `https`, non-`www` → `www`, and proxy-level
  redirects. Fix it on the app side by setting `CRM_URL` to the final
  scheme/host, e.g. `CRM_URL=https://crm.example.com` — never
  `http://crm.example.com`.
- **Nothing appears in the log at all** → the 405 was produced *in front of*
  Laravel and the request never reached the app. Almost always `CRM_URL` points
  at the host serving the Vue SPA (or any static host) instead of the API:
  nginx answers `405 Not Allowed` when a POST lands on a static file. The body
  of such a response is nginx HTML, not our JSON — that alone identifies it.

**Verify a base URL before pointing webhooks at it:**

```bash
curl -i https://crm.example.com/api/webhooks/ping
```

A JSON body with `"success": true` means the URL reaches the CRM API. It also
echoes `received_over` (the scheme Laravel actually saw) and the exact webhook
URLs to use. Any redirect in the way shows up as a `301`/`302` in `curl -i`
before the JSON — that is the thing to remove.

Belt and braces on the app side: send webhooks without following redirects, so
a misconfigured URL fails loudly instead of silently degrading to a GET:

```php
Http::withOptions(['allow_redirects' => false])->post($url, $data);
```

### Issue 7: Merchant receives the installation email repeatedly (e.g. every hour)

**Cause:** the install webhook is not delivered exactly once. Retry jobs and
periodic sync jobs on the app side re-send it for stores the CRM already has,
and the CRM used to send the installation email on *every* delivery.

**Fixed in the CRM.** `WebhookController::install()` now only sends the email
when the delivery is a genuinely new install, or a real reinstall of a store
that was marked inactive. On top of that, `installations.install_email_sent_at`
suppresses a second install email within 24 hours no matter what arrives;
`uninstall_email_sent_at` does the same for uninstall emails. Repeated
deliveries still refresh the installation record — they just don't email, and
they no longer inflate `install_count`.

Every decision is logged, so "why did/didn't this merchant get an email" is
answerable after the fact:

```bash
grep -E "Install email (sent|skipped)" storage/logs/webhooks-$(date +%F).log
```


---

## Webhook Flow Diagram

```
Shopify App Event
       ↓
WebhookService.sendInstallation()
       ↓
Generate HMAC Signature
       ↓
HTTP POST to CRM
       ↓
CRM: VerifyWebhookSignature (middleware)
       ↓
CRM: WebhookController
       ↓
Validate Data
       ↓
Find/Create App
       ↓
Create/Update Installation
       ↓
Return Success Response
       ↓
Shopify App receives confirmation
       ↓
Update local records (optional)
```

---

## Next Steps

After implementing webhooks, you can:

1. **Real-time Dashboard Updates:** Use Laravel Broadcasting to push updates to Vue frontend
2. **Email Notifications:** Send emails when installations occur
3. **Analytics:** Track installation trends over time
4. **Automated Actions:** Trigger workflows based on webhooks
5. **Webhook History:** Show webhook logs in the CRM UI

---

## Complete Checklist

### Laravel CRM
- [ ] WebhookController created
- [ ] Webhook routes added
- [ ] config/webhook.php created
- [ ] WEBHOOK_SECRET in .env
- [ ] Middleware created (optional)
- [ ] Middleware registered (optional)
- [ ] Routes use middleware (optional)
- [ ] Tested with cURL

### Shopify App
- [ ] WebhookService created
- [ ] config/crm.php created
- [ ] CRM_URL in .env
- [ ] CRM_WEBHOOK_SECRET in .env (same as CRM)
- [ ] Webhook calls implemented on install
- [ ] Webhook calls implemented on uninstall
- [ ] Webhook calls implemented on plan change
- [ ] Tested sending webhooks

### Testing
- [ ] Installation webhook works
- [ ] Uninstallation webhook works
- [ ] Plan change webhook works
- [ ] Duplicate prevention works
- [ ] Install count increments correctly
- [ ] Signature verification works (if enabled)
- [ ] Error handling works
- [ ] Logs are being written

### Production
- [ ] Using HTTPS
- [ ] Signature verification enabled
- [ ] Rate limiting configured
- [ ] Error monitoring setup
- [ ] Webhook logs table created
- [ ] Retry logic implemented