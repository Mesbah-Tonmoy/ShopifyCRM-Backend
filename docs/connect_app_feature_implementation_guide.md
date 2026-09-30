# Connect App Feature Implementation Guide

## Overview
This feature allows you to connect external Shopify apps by entering their URL. The system will sync app data and all installations automatically.

---

## Frontend Changes (Already Implemented)

### 1. AppsPage.vue
- Added "Connect App" button
- Added modal with app URL input
- Added connect functionality with loading states
- Shows success/error messages

### 2. Apps Store (Pinia)
- Added `connectApp()` method
- Handles API call to connect new apps

---

## Backend Changes (Laravel)

### 1. Update AppController.php

Replace the `store()` method in `app/Http/Controllers/Api/AppController.php` with the updated version provided.

**Key changes:**
- Accepts `app_url` instead of individual fields
- Makes GET request to `{app_url}/api/sync-crm`
- Parses response and saves app data
- Syncs all installations
- Uses `updateOrCreate` to prevent duplicates

**Don't forget to add the import:**
```php
use Illuminate\Support\Facades\Http;
use App\Models\Installation;
```

### 2. Update App Model

Make sure the `app_url` field is fillable in `app/Models/App.php`:

```php
protected $fillable = [
    'app_name',
    'app_url',
    'app_store_url',
    'icon',
    'last_synced',
];
```

### 3. Update Installation Model

Ensure proper fillable fields in `app/Models/Installation.php`:

```php
protected $fillable = [
    'app_id',
    'store_name',
    'store_url',
    'email',
    'shop_owner_name',
    'currency',
    'shopify_plan',
    'app_plan',
    'plan_started_at',
    'plan_expires_at',
    'is_active',
    'install_count',
];
```

---

## Shopify App Implementation

Each Shopify app needs to implement the sync endpoint.

### 1. Create the Sync Endpoint

Add this route to your Shopify app's `routes/api.php`:

```php
Route::get('/sync-crm', [SyncCrmController::class, 'sync']);
```

### 2. Create the Controller

Create `app/Http/Controllers/Api/SyncCrmController.php` with the provided code, or adapt it to your app's structure.

### 3. Expected Response Format

Your endpoint must return data in this exact format:

```json
{
  "success": true,
  "AppData": {
    "title": "Your App Name",
    "appStoreAppUrl": "https://apps.shopify.com/your-app",
    "icon": {
      "url": "https://your-cdn.com/icon.png"
    }
  },
  "stores": [
    {
      "id": "unique-store-id",
      "shopDomain": "store.myshopify.com",
      "shopifyShopId": "gid://shopify/Shop/123456",
      "name": "Store Name",
      "email": "owner@example.com",
      "accessToken": "token",
      "scope": "read_products,write_products",
      "currencyCode": "USD",
      "primaryDomain": "https://store.myshopify.com",
      "shopifyPlan": "Basic",
      "isShopifyPlus": false,
      "timezone": "America/New_York",
      "appPlan": "Premium",
      "planStartedAt": "2025-01-01T00:00:00.000Z",
      "planExpiresAt": null,
      "isActive": true,
      "installedAt": "2025-01-01T00:00:00.000Z",
      "uninstalledAt": null,
      "lastActiveAt": "2025-01-20T00:00:00.000Z",
      "createdAt": "2025-01-01T00:00:00.000Z",
      "updatedAt": "2025-01-20T00:00:00.000Z"
    }
  ]
}
```

---

## Testing the Feature

### 1. Start Both Applications

**Laravel CRM:**
```bash
php artisan serve
```

**Vue Frontend:**
```bash
npm run dev
```

**Shopify App (example):**
```bash
php artisan serve --port=8001
```

### 2. Test the Connection

1. Open the Vue app at `http://localhost:5173`
2. Login and navigate to Apps page
3. Click "Connect App" button
4. Enter your Shopify app URL: `http://localhost:8001`
5. Click "Connect"

### 3. Verify the Data

After successful connection:
- App should appear in the apps list
- App icon, name, and URLs should be displayed
- Installation count should show
- Check installations page to see synced stores

---

## Error Handling

The implementation handles various error scenarios:

### Frontend
- Empty URL validation
- Connection timeout
- Invalid response format
- Display error messages to user

### Backend
- Invalid URL format
- Failed HTTP request (timeout, connection error)
- Invalid response structure
- Duplicate prevention using `updateOrCreate`
- Database transaction for data integrity

---

## Database Behavior

### Duplicate Prevention

The system uses `updateOrCreate` to prevent duplicates:

**Apps:**
- Unique key: `app_url`
- If app with same URL exists, it updates the data
- `last_synced` timestamp is updated on each sync

**Installations:**
- Unique key: combination of `app_id` and `store_url`
- If installation exists, it updates the data
- Install count remains unchanged (you can modify this behavior)

---

## Customization Options

### 1. Auto-sync Interval

Add a scheduled task to auto-sync apps:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->call(function () {
        $apps = \App\Models\App::all();
        foreach ($apps as $app) {
            if ($app->app_url) {
                try {
                    $syncUrl = rtrim($app->app_url, '/') . '/api/sync-crm';
                    $response = \Http::timeout(30)->get($syncUrl);
                    
                    if ($response->successful()) {
                        $data = $response->json();
                        // Update installations
                        if (isset($data['stores'])) {
                            foreach ($data['stores'] as $store) {
                                \App\Models\Installation::updateOrCreate(
                                    [
                                        'app_id' => $app->id,
                                        'store_url' => $store['shopDomain'],
                                    ],
                                    [
                                        'store_name' => $store['name'],
                                        'email' => $store['email'],
                                        'currency' => $store['currencyCode'],
                                        'shopify_plan' => $store['shopifyPlan'],
                                        'app_plan' => $store['appPlan'],
                                        'is_active' => $store['isActive'],
                                    ]
                                );
                            }
                        }
                        $app->update(['last_synced' => now()]);
                    }
                } catch (\Exception $e) {
                    \Log::error("Failed to sync app {$app->id}: " . $e->getMessage());
                }
            }
        }
    })->hourly(); // Run every hour
}
```

### 2. Manual Re-sync Button

Add a re-sync button for each app:

**Frontend (AppsPage.vue):**
```vue
<button
  @click.stop="resyncApp(app.id)"
  class="text-sm text-indigo-600 hover:text-indigo-900"
>
  🔄 Sync
</button>
```

**Backend (Add to AppController.php):**
```php
public function sync(App $app)
{
    if (!$app->app_url) {
        return response()->json([
            'success' => false,
            'message' => 'App URL not configured',
        ], 400);
    }

    try {
        $syncUrl = rtrim($app->app_url, '/') . '/api/sync-crm';
        $response = Http::timeout(30)->get($syncUrl);
        
        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync with app',
            ], 400);
        }

        $data = $response->json();

        // Update app data
        $appData = $data['AppData'];
        $app->update([
            'app_name' => $appData['title'],
            'app_store_url' => $appData['appStoreAppUrl'] ?? null,
            'icon' => $appData['icon']['url'] ?? null,
            'last_synced' => now(),
        ]);

        // Sync installations
        if (isset($data['stores'])) {
            foreach ($data['stores'] as $store) {
                Installation::updateOrCreate(
                    [
                        'app_id' => $app->id,
                        'store_url' => $store['shopDomain'],
                    ],
                    [
                        'store_name' => $store['name'],
                        'email' => $store['email'],
                        'currency' => $store['currencyCode'],
                        'shopify_plan' => $store['shopifyPlan'],
                        'app_plan' => $store['appPlan'],
                        'plan_started_at' => $store['planStartedAt'] ?? null,
                        'plan_expires_at' => $store['planExpiresAt'] ?? null,
                        'is_active' => $store['isActive'],
                    ]
                );
            }
        }

        $app->loadCount(['installations', 'activeInstallations']);

        return response()->json([
            'success' => true,
            'message' => 'App synced successfully',
            'data' => $app,
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to sync app: ' . $e->getMessage(),
        ], 500);
    }
}
```

**Add route:**
```php
Route::post('/apps/{app}/sync', [AppController::class, 'sync']);
```

### 3. Sync Status Indicator

Show last sync time in the UI:

```vue
<span class="text-xs text-gray-500">
  Last synced: {{ formatDate(app.last_synced) }}
</span>
```

### 4. Handle Install Count Properly

To track reinstalls, modify the installation sync:

```php
Installation::updateOrCreate(
    [
        'app_id' => $app->id,
        'store_url' => $store['shopDomain'],
    ],
    [
        'store_name' => $store['name'],
        'email' => $store['email'],
        // ... other fields
    ]
)->increment('install_count'); // Increment on each sync if needed
```

---

## Security Considerations

### 1. Rate Limiting

Add rate limiting to prevent abuse:

```php
// routes/api.php
Route::middleware(['auth:sanctum', 'throttle:10,1'])->group(function () {
    Route::post('/apps', [AppController::class, 'store']);
});
```

### 2. Validate Response Data

Add validation for the sync response:

```php
$validator = Validator::make($data, [
    'success' => 'required|boolean',
    'AppData' => 'required|array',
    'AppData.title' => 'required|string',
    'stores' => 'required|array',
    'stores.*.shopDomain' => 'required|string',
    'stores.*.name' => 'required|string',
    'stores.*.email' => 'required|email',
]);

if ($validator->fails()) {
    return response()->json([
        'success' => false,
        'message' => 'Invalid response format from app',
    ], 400);
}
```

### 3. Whitelist Allowed Domains

Only allow connections to trusted domains:

```php
$allowedDomains = config('app.allowed_app_domains', []);
$urlHost = parse_url($validated['app_url'], PHP_URL_HOST);

if (!in_array($urlHost, $allowedDomains)) {
    return response()->json([
        'success' => false,
        'message' => 'This domain is not allowed',
    ], 403);
}
```

### 4. Authenticate Sync Requests

Add authentication to your Shopify app's sync endpoint:

```php
// In your Shopify app
public function sync(Request $request)
{
    $apiKey = $request->header('X-CRM-API-Key');
    
    if ($apiKey !== config('crm.api_key')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }
    
    // ... return data
}
```

**Update Laravel CRM request:**
```php
$response = Http::timeout(30)
    ->withHeaders(['X-CRM-API-Key' => config('crm.api_key')])
    ->get($syncUrl);
```

---

## Troubleshooting

### Issue: "Failed to sync with app"

**Possible causes:**
- App URL is incorrect
- Sync endpoint not implemented
- App is not running
- CORS issues
- Timeout (slow response)

**Solutions:**
- Verify app URL format (should include http:// or https://)
- Check if endpoint exists: `GET {app_url}/api/sync-crm`
- Ensure app is running and accessible
- Increase timeout if needed
- Check Laravel logs: `storage/logs/laravel.log`

### Issue: "Invalid response format"

**Possible causes:**
- Response doesn't match expected structure
- Missing required fields
- Wrong data types

**Solutions:**
- Check the response structure matches the example
- Ensure all required fields are present
- Verify data types (boolean for `isActive`, etc.)

### Issue: Duplicate installations

**Possible causes:**
- `store_url` not unique enough
- Multiple apps with different URLs pointing to same stores

**Solutions:**
- Use `shopDomain` as unique identifier
- Add composite unique index in migration:
  ```php
  $table->unique(['app_id', 'store_url']);
  ```

### Issue: Modal doesn't close after success

**Possible causes:**
- Error in success handling
- Component state not updating

**Solutions:**
- Check browser console for errors
- Verify `closeModal()` is called
- Ensure `fetchApps()` completes successfully

---

## Testing Checklist

- [ ] Modal opens when clicking "Connect App"
- [ ] URL validation works (empty, invalid format)
- [ ] Loading state shows during connection
- [ ] Success: App appears in list immediately
- [ ] Success: Modal closes automatically
- [ ] Error: Error message displays in modal
- [ ] App data saved correctly (name, icon, URL)
- [ ] Installations synced correctly
- [ ] Installation count displays correctly
- [ ] Active installation count displays correctly
- [ ] Duplicate prevention works (connecting same app twice)
- [ ] Last synced timestamp updates

---

## Next Steps

After implementing this feature, you might want to add:

1. **Bulk sync** - Sync all apps at once
2. **Sync history** - Track when each sync happened
3. **Sync status** - Show if sync is in progress
4. **Webhook integration** - Real-time updates instead of polling
5. **Selective sync** - Choose which data to sync
6. **Conflict resolution** - Handle data conflicts better
7. **Backup before sync** - Preserve data before overwriting

---

## API Reference

### Connect App Endpoint

**URL:** `POST /api/apps`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "app_url": "https://your-shopify-app.com"
}
```

**Success Response (201):**
```json
{
  "success": true,
  "message": "App connected successfully",
  "data": {
    "id": 1,
    "app_name": "Your App",
    "app_url": "https://your-shopify-app.com",
    "app_store_url": "https://apps.shopify.com/your-app",
    "icon": "https://cdn.example.com/icon.png",
    "last_synced": "2025-01-20T10:00:00.000000Z",
    "created_at": "2025-01-20T10:00:00.000000Z",
    "updated_at": "2025-01-20T10:00:00.000000Z",
    "installations_count": 5,
    "active_installations_count": 3
  }
}
```

**Error Response (400):**
```json
{
  "success": false,
  "message": "Failed to sync with app"
}
```

**Error Response (500):**
```json
{
  "success": false,
  "message": "Failed to connect app: Connection timeout"
}
```