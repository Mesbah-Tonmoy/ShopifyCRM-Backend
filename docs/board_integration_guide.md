# Embedding the feature board and changelog

How a Shopify app embeds the CRM's feature request board, and reads its
"What's new" changelog. Written for whoever is wiring this into an app, not for
whoever built the CRM.

Two independent things live here:

| | What it is | How the app uses it |
| --- | --- | --- |
| **Feature board** | Merchants submit, vote on and discuss requests | An `<iframe>` |
| **What's new** | Entries the team publishes; not tied to requests | A JSON endpoint the app renders itself |

---

## 1. Provision the board

In the CRM, open **Board Settings**, pick the app, and press **Provision**. That
creates the board and its signing credentials, and shows you two values:

- `board_public_key` — identifies the app. Safe to log.
- `board_secret` — **shown once.** Rotating it invalidates every token signed
  with the old one, so schedule a rotation rather than doing it at peak.

Put both in the Shopify app's server environment:

```
CRM_BOARD_KEY=bk_...
CRM_BOARD_SECRET=...
```

The secret never reaches a browser. That is the whole point of the design: a
merchant's browser cannot assert a shop domain it does not own, because only
your server can produce a valid signature.

## 2. Sign a token, server-side

The token is short-lived and identifies one store:

```
base64url( json payload ) + "." + hex hmac_sha256( base64url payload, secret )
```

Payload fields:

| Field | Required | Notes |
| --- | --- | --- |
| `key` | yes | Your `CRM_BOARD_KEY` |
| `shop` | yes | `acme.myshopify.com` — the store's permanent domain |
| `name` | no | Store name, shown as the request's author |
| `email` | no | Where status emails go if the CRM has no installation record |
| `iat` | yes | Issued-at, unix seconds |
| `exp` | no | Defaults to `iat + 300` |

A token is valid for **5 minutes** by default (`BOARD_TOKEN_TTL`), with 60
seconds of clock-skew tolerance. It only has to survive page render: the board
exchanges it for a **24 hour** session (`BOARD_SESSION_TTL`) on load.

### Laravel

```php
$payload = rtrim(strtr(base64_encode(json_encode([
    'key'   => config('services.crm.board_key'),
    'shop'  => $shop->domain,
    'name'  => $shop->name,
    'email' => $shop->email,
    'iat'   => time(),
    'exp'   => time() + 300,
])), '+/', '-_'), '=');

$token = $payload . '.' . hash_hmac('sha256', $payload, config('services.crm.board_secret'));
```

### Node

```js
import { createHmac } from 'node:crypto';

const payload = Buffer.from(JSON.stringify({
  key:   process.env.CRM_BOARD_KEY,
  shop:  shop.domain,
  name:  shop.name,
  email: shop.email,
  iat:   Math.floor(Date.now() / 1000),
  exp:   Math.floor(Date.now() / 1000) + 300,
})).toString('base64url');

const token = `${payload}.${createHmac('sha256', process.env.CRM_BOARD_SECRET)
  .update(payload)
  .digest('hex')}`;
```

`base64url` matters — the CRM translates `-_` back to `+/` before decoding, and
padding is stripped.

## 3. Embed it

```html
<iframe src="https://crm.zapioapps.com/board/{slug}?token={{ $token }}&embed=1"
        style="width:100%;height:900px;border:0"></iframe>
```

`embed=1` hides the standalone chrome (app header, theme toggle, footer). The
board also detects being framed, so the flag is belt-and-braces.

The token is stripped from the URL as soon as it is exchanged, so it is not left
in history or copied into a shared link.

### Optional: let the iframe size itself

The board posts its content height to the parent whenever it changes. Listening
removes the fixed height and the inner scrollbar:

```html
<iframe id="crm-board" src="..." style="width:100%;height:600px;border:0"></iframe>
<script>
  addEventListener('message', (event) => {
    if (event.data?.type !== 'zapio-board:height') return;
    document.getElementById('crm-board').style.height = `${event.data.height}px`;
  });
</script>
```

Check `event.origin` against the CRM's origin if the page hosts other frames.

## 4. Read the changelog

Published entries for an app, no authentication:

```
GET https://crm.zapioapps.com/backendapp/api/public/apps/{appId}/features
```

Query: `search`, `date_from`, `date_to`, `sort_by` (`release_date`, `title`,
`created_at`), `sort_order`, `per_page` (max 50), `page`.

```json
{
  "success": true,
  "data": {
    "data": [
      {
        "id": 12,
        "title": "Bulk CSV import",
        "description": "Import openings from a spreadsheet.",
        "image_url": "https://crm.zapioapps.com/backendapp/storage/features/abc.png",
        "release_date": "2026-08-01T00:00:00.000000Z",
        "is_published": true
      }
    ],
    "current_page": 1, "last_page": 1, "per_page": 15, "total": 1
  }
}
```

Unpublished entries are never returned here. Render this in your own UI — with
Polaris components, since it is your app's own page.

**Fetch this from your server, not the browser.** The CRM only sends CORS
headers for its own origin, so a `fetch` from the merchant's browser is blocked.
Your app already has a server; proxy the call through it and the endpoint stays
closed to everyone else's front end.

---

## Deployment requirements

### Frame ancestors (required)

The board is served by the **frontend SPA**, so the header must be set by
whatever serves the built SPA — not by the backend's nginx.

```nginx
add_header Content-Security-Policy "frame-ancestors 'self' https://*.myshopify.com https://admin.shopify.com" always;
```

Without it, browsers refuse to frame the board and the merchant sees
"refused to connect". In development `frontend/vite.config.ts` sets the same
header; `VITE_BOARD_FRAME_ANCESTORS` overrides it, and `=off` disables it
entirely when you need to rule CSP out of an embed failure.

### Everything must be HTTPS

A page served over HTTPS cannot frame an HTTP origin. A board on `localhost`
cannot be framed by a public tunnel URL either — serve both over the same
scheme when testing.

### The queue must be running

Status-change emails are queued. With `QUEUE_CONNECTION=sync` they are sent
inside the HTTP request, which made a status change take about ten seconds per
recipient. Run `php artisan queue:work` (the `queue` service in
`docker-compose.yml`) and keep `QUEUE_CONNECTION=database`.

Failed sends retry three times and then land in `failed_jobs`. Check that table
when someone reports a missing notification.

### Upload size

Attachments are capped at 5MB in the application. nginx must allow slightly
more (`client_max_body_size 6M`), or it cuts the request off with a bare 413
before Laravel can return a validation message.

---

## Endpoint reference

Board endpoints are under `/api/board`. Reads are open so the roadmap is
publicly browsable; writes need the session minted in step 2, sent as
`Authorization: Board <session>`.

| Method | Path | Auth | Limit |
| --- | --- | --- | --- |
| POST | `/session` | token | 30/min per IP |
| GET | `/{slug}/config` | optional | 120/min per IP |
| GET | `/{slug}/requests` | optional | 120/min per IP |
| GET | `/{slug}/roadmap` | optional | 120/min per IP |
| GET | `/{slug}/requests/{id}/comments` | optional | 120/min per IP |
| GET | `/{slug}/me` | session | 120/min per IP |
| POST | `/{slug}/requests` | session | 5/min per store |
| POST | `/{slug}/requests/{id}/comments` | session | 5/min per store |
| POST/DELETE | `/{slug}/requests/{id}/vote` | session | 30/min per store |
| POST/DELETE | `/{slug}/requests/{id}/subscribe` | session | 30/min per store |

Write limits key on the **store**, not the IP, so one shop cannot flood a board
and a shared IP does not lock out unrelated stores.

## When something goes wrong

**"Refused to connect"** — `frame-ancestors` is missing, or the parent is HTTPS
and the board is HTTP. Set `VITE_BOARD_FRAME_ANCESTORS=off` to confirm whether
CSP is the cause before changing anything else.

**401 on every write** — the session expired (24h) or the token was already
stale when the page loaded. Mint a fresh token and reload.

**"Unknown board key"** — the app was never provisioned, or `CRM_BOARD_KEY`
belongs to a different environment.

**"Board token signature mismatch"** — the secret is wrong, or the payload was
re-encoded between signing and sending. Sign the exact `base64url` string you
put in the URL, not the JSON.

**Merchant shows as read-only** — the board loaded without a token. Reads work,
writes do not.
