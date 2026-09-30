# Feature Requests, Voting & Roadmap — Implementation Plan

A features.vote-style board built into the Shopify CRM, embeddable in any of your Shopify apps.

**Status:** Planning complete, not yet implemented.
**Date:** 2026-08-12

---

## 1. Decisions (locked)

| Decision | Choice |
|---|---|
| Merchant-facing delivery | **Hosted board page** served by the CRM at `/board/{slug}`, embedded by each Shopify app in an iframe (or linked out). Build once, zero per-app UI work. |
| Voter identity | **Per store (installation)**. One vote per shop domain per request. Enables plan-weighted prioritisation and status-change emails. |
| API auth | **Per-app key + HMAC-signed shop token.** Each app gets `board_public_key` + `board_secret`; the app's backend signs a short-lived token containing the shop domain. |
| v1 scope | Core (submit, vote, 5 status columns, admin moderation) **+ email notifications + changelog linking**. |
| Deferred to v2 | Comments/replies, duplicate merge, internal-only notes beyond a single admin field, realtime vote updates. |

### Naming — avoid the collision

The existing `Feature` model is your **"What's New" changelog**. The new module is deliberately named differently:

- `Feature` (existing) = shipped changelog entry.
- `FeatureRequest` (new) = merchant-submitted idea that gets voted on.
- A completed `FeatureRequest` can be **linked to** a `Feature` — that's the changelog integration.

---

## 2. Architecture at a glance

```
┌──────────────────────────┐         ┌────────────────────────────────────┐
│ Your Shopify App         │         │ Shopify CRM (this repo)            │
│ (embedded admin, Polaris)│         │                                    │
│                          │         │  Vue SPA                           │
│  backend signs token ────┼────┐    │   /board/:slug   (public, iframe)  │
│  hmac(shop|exp, secret)  │    │    │   /feature-requests (admin kanban) │
│                          │    │    │                                    │
│  <iframe src=".../board/ │    │    │  Laravel API                       │
│    acme?token=..."/>     │────┼───▶│   /api/board/*   (HMAC → session)  │
└──────────────────────────┘    │    │   /api/feature-requests/* (sanctum)│
                                │    │                                    │
                                └───▶│  MySQL: feature_requests, votes,   │
                                     │         feature_boards, status_logs│
                                     └────────────────────────────────────┘
```

Everything lives in the existing repo: Laravel `backend/`, Vue SPA `frontend/`. No new service, no new container.

---

## 3. Database schema

### 3.1 `apps` — new columns (migration: `add_board_fields_to_apps_table`)

| Column | Type | Notes |
|---|---|---|
| `board_slug` | string, unique, nullable | Public URL segment, e.g. `kaching-cart-drawer`. Auto-generated from `app_name`. |
| `board_public_key` | string, unique, nullable | `bk_live_...`. Identifies the app in signed tokens. |
| `board_secret` | string, nullable | HMAC signing secret. Stored encrypted (`encrypted` cast), shown once + rotatable. |

### 3.2 `feature_boards` — per-app board configuration (1:1 with app)

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `app_id` | FK apps, unique, cascade | |
| `title` | string | Board heading, defaults to app name |
| `intro` | text, nullable | Blurb above the submit form |
| `is_enabled` | bool, default true | Kill switch — board returns 404 when off |
| `allow_submissions` | bool, default true | |
| `allow_voting` | bool, default true | |
| `require_approval` | bool, default true | New requests land in `pending` and are hidden from the public board until approved |
| `show_vote_counts` | bool, default true | |
| `visible_statuses` | json | Which columns render on the Roadmap tab + their order/labels/emoji |
| `submission_limit_per_day` | unsigned int, default 5 | Per store |
| `notify_on_status_change` | bool, default true | |
| `theme` | json, nullable | accent colour, logo URL, default light/dark |
| `allowed_frame_origins` | json, nullable | Extra origins permitted to iframe this board |
| timestamps | | |

### 3.3 `feature_requests`

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `app_id` | FK apps, cascade | |
| `installation_id` | FK installations, nullable, nullOnDelete | Submitting store |
| `submitter_shop_domain` | string, nullable | Denormalised — survives uninstall |
| `submitter_email` | string, nullable | Copied from installation at submit time |
| `title` | string(180) | |
| `description` | text, nullable | Plain text in v1, sanitised on output |
| `image` | string, nullable | Optional screenshot, `storage/board` disk — mirrors the `Feature` image pattern |
| `status` | enum | `pending`, `approved`, `in_progress`, `completed`, `rejected` — default `pending` |
| `status_note` | text, nullable | **Public** response shown on the card ("shipping in v3.2", "why rejected") |
| `admin_note` | text, nullable | **Internal only**, never leaves the admin API |
| `votes_count` | unsigned int, default 0 | Denormalised counter, kept in sync inside a transaction |
| `is_visible` | bool, default false | Public board visibility. Auto-true when `require_approval` is off |
| `is_pinned` | bool, default false | Sticks to the top of its column |
| `sort_order` | int, default 0 | Manual ordering within a column (drag & drop) |
| `feature_id` | FK features, nullable, nullOnDelete | **Changelog link** |
| `completed_at` | timestamp, nullable | |
| `created_by_user_id` | FK users, nullable | Set when an admin creates the request themselves |
| timestamps + softDeletes | | Soft delete so votes/history survive moderation |

Indexes: `(app_id, status)`, `(app_id, is_visible, votes_count)`, `(app_id, created_at)`, `installation_id`, `feature_id`.

### 3.4 `feature_request_votes`

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `feature_request_id` | FK, cascade | |
| `app_id` | FK, cascade | Denormalised for fast per-app queries |
| `installation_id` | FK, nullable, nullOnDelete | |
| `voter_key` | string(191) | Normalised shop domain (lowercased, `.myshopify.com` stripped of protocol). The dedupe key. |
| `weight` | unsigned tinyint, default 1 | Reserved for plan-weighted scoring later |
| `ip_address` | string(45), nullable | Abuse forensics |
| timestamps | | |

Unique: `(feature_request_id, voter_key)`. Index: `(app_id, voter_key)`.

### 3.5 `feature_request_status_logs`

`id`, `feature_request_id` (FK cascade), `from_status`, `to_status`, `note` (nullable), `user_id` (FK users, nullable), `notified_at` (nullable), `created_at`.

Powers the audit trail in the detail drawer, and prevents double-sending status emails.

---

## 4. Authentication model

### 4.1 Token minted by the Shopify app (server side)

```php
// Inside YOUR Shopify app, when rendering the Feature Requests page
$payload = base64url(json_encode([
    'key' => config('crm.board_public_key'),   // bk_live_xxx
    'shop' => $shop->domain,                   // acme.myshopify.com
    'name' => $shop->name,                     // optional, improves first-time records
    'email' => $shop->email,                   // optional
    'iat' => time(),
    'exp' => time() + 300,                     // 5 minutes — it's exchanged immediately
]));
$token = $payload . '.' . hash_hmac('sha256', $payload, config('crm.board_secret'));
```

The secret **never** reaches the browser. The token is short-lived and single-purpose.

### 4.2 Exchange for a board session

`POST /api/board/session { token }` → `VerifyBoardToken` middleware:

1. Split payload/signature, decode payload.
2. Look up app by `board_public_key` (fail closed if missing/disabled).
3. `hash_equals` against `hash_hmac('sha256', $payload, decrypt($app->board_secret))`.
4. Check `exp` (and reject `iat` more than a few minutes in the future — clock skew guard).
5. Normalise `shop`; resolve the `Installation` for `(app_id, shop)`. If absent, create a lightweight one (or record shop domain only — configurable, default: match-or-null so the CRM's installation data stays webhook-authoritative).
6. Issue a **board session**: signed, 24h, containing `app_id`, `installation_id`, `voter_key`. Implementation: Laravel signed payload (`encrypt()` of a small array) returned as an opaque string; the SPA keeps it in `sessionStorage` and sends `Authorization: Board <session>`.

`BoardSession` middleware decrypts it and injects `$request->board` (app + voter identity) for write endpoints.

### 4.3 Read vs write

- **Read** endpoints (`config`, `requests` list, single request) work with **no token** — the roadmap is publicly browsable, which is also what makes the page shareable.
- **Write** endpoints (submit, vote, unvote) require a valid board session → identity is always a verified shop domain. Browser-forged votes are impossible.

### 4.4 Rate limiting & abuse

- `throttle:60,1` on read, `throttle:20,1` on vote, `throttle:5,1` on submit — plus a hard per-store daily submit cap from `feature_boards.submission_limit_per_day`.
- Duplicate-title soft check on submit ("Did you mean this existing request?") returning near matches before creating.

---

## 5. API surface

### 5.1 Board API — `/api/board` (public, no Sanctum)

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| POST | `/session` | HMAC token | Exchange app token → board session |
| GET | `/{slug}/config` | none | Board title, intro, theme, columns, toggles, whether submissions are open |
| GET | `/{slug}/requests` | optional session | Filters: `status`, `search`, `sort` (`votes`\|`newest`\|`trending`), `per_page`, `page`. With a session, each row includes `has_voted`. |
| GET | `/{slug}/roadmap` | optional session | All visible statuses grouped into columns, N per column + counts — one call powers the whole roadmap screen |
| GET | `/{slug}/requests/{id}` | optional session | |
| POST | `/{slug}/requests` | session | Submit |
| POST | `/{slug}/requests/{id}/vote` | session | Idempotent add |
| DELETE | `/{slug}/requests/{id}/vote` | session | Remove |
| GET | `/{slug}/me` | session | This store's submissions + voted IDs (for hydrating vote state) |

`pending` requests are excluded from public responses unless `is_visible` — except the submitter always sees their own.

### 5.2 Admin API — Sanctum + `permission:` middleware

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/feature-requests` | `feature_requests.view` |
| GET | `/feature-requests/stats` | `feature_requests.view` |
| GET | `/feature-requests/{id}` | `feature_requests.view` |
| POST | `/feature-requests` | `feature_requests.add` |
| PUT | `/feature-requests/{id}` | `feature_requests.edit` |
| POST | `/feature-requests/{id}/status` | `feature_requests.edit` |
| POST | `/feature-requests/bulk-status` | `feature_requests.edit` |
| POST | `/feature-requests/{id}/reorder` | `feature_requests.edit` |
| POST | `/feature-requests/{id}/link-feature` | `feature_requests.edit` |
| DELETE | `/feature-requests/{id}` | `feature_requests.delete` |
| GET | `/apps/{app}/feature-requests` | `feature_requests.view` |
| GET/PUT | `/apps/{app}/board` | `board_settings.edit` |
| POST | `/apps/{app}/board/rotate-secret` | `board_settings.edit` |

All responses follow the existing `{ success, message?, data }` envelope. Filtering/pagination mirrors `FeatureController::index`.

### 5.3 ACL additions (`ACLSeeder`)

```
Feature Requests View   => feature_requests.view
Feature Requests Add    => feature_requests.add
Feature Requests Edit   => feature_requests.edit
Feature Requests Delete => feature_requests.delete
Board Settings Edit     => board_settings.edit
```

---

## 6. Frontend

### 6.1 Admin surface (inside the existing SPA + `MainLayout`)

**`views/FeatureRequestsPage.vue`** — the operator view, modelled on your screenshot:

- App selector at the top (or `?app_id=` deep link from `AppsPage`, matching the existing installations pattern).
- **Kanban mode**: 5 columns — Pending 👀 / Approved 👍 / In Progress 🛠️ / Completed ✅ / Rejected ❌ — each with a count badge and its own scroll area. Drag a card between columns → status change modal (public note + "notify voters" checkbox). Drag within a column → `sort_order`.
- **Table mode** toggle for bulk triage: checkboxes, bulk status change, sort by votes/date, search, date range — reusing `SearchInput`, `SelectInput`, `SkeletonLoader`, `PageHeader`.
- **Detail drawer**: description + screenshot, submitting store (links to the installation), **voter list with store name / Shopify plan / app plan / vote date** — this is the commercially useful part, it tells you *which* stores want a feature — status history timeline, public status note, internal admin note, and the "Link to What's New" action.

**`views/BoardSettingsPage.vue`** (or a Board tab on `AppsPage`) — slug, enable toggles, moderation, per-day limit, visible columns, theme, `board_public_key` (copyable), `board_secret` (masked + rotate with confirmation), and a **copy-paste embed snippet** generated per app.

**Plumbing**: `services/featureRequestService.ts` (mirrors `featureService.ts`), sidebar entry gated on `feature_requests.view`, router entries, plus a "Top requests" widget on `DashboardPage`.

### 6.2 Public board (same SPA, public routes, no `MainLayout`)

Route `/board/:slug` with children `requests` and `roadmap`, registered **outside** the authenticated tree — the existing guard only blocks routes carrying `requiresAuth`, so a top-level route passes through untouched.

- **`layouts/BoardLayout.vue`** — app icon + name, tabs (💡 Feature Requests / 📊 Roadmap), light/dark toggle, and a session badge showing the connected shop domain (where features.vote shows "Anonymous session").
- **`BoardRequestsView.vue`** — submit form (title, description, optional screenshot) with the duplicate hint, then the list: vote pill on the left, title/description, "See more" expansion, search + sort dropdown, pagination.
- **`BoardRoadmapView.vue`** — the horizontal 5-column layout from the screenshot, votes visible on each card, votable inline.
- **Iframe hygiene**: `?embed=1` compact mode, `postMessage` height reporting so the parent auto-resizes, and a `frame-ancestors` CSP built from `allowed_frame_origins` + `*.myshopify.com` + `admin.shopify.com`. Nginx must **not** send a blanket `X-Frame-Options: DENY` for these routes.
- Optimistic vote UI with rollback on failure; vote state hydrated from `/me` on load.

---

## 7. Email notifications

Extend the existing `EmailTemplate` module with new `type` values (per app, so each app can word them differently):

| Type | Trigger | To |
|---|---|---|
| `feature_request_received` | Submission created | Submitting store |
| `feature_request_approved` | → `approved` | Submitter |
| `feature_request_in_progress` | → `in_progress` | Submitter + all voters |
| `feature_request_completed` | → `completed` | Submitter + all voters |
| `feature_request_rejected` | → `rejected` | Submitter only |

- Variables: `{{store_name}}`, `{{shop_domain}}`, `{{app_name}}`, `{{request_title}}`, `{{status}}`, `{{status_note}}`, `{{board_url}}`.
- Dispatched as **queued jobs** (`SendFeatureRequestStatusEmail`), chunked over voters, guarded by `feature_request_status_logs.notified_at` so a status flip-flop can't spam.
- Respects `feature_boards.notify_on_status_change` and the per-status "notify voters" checkbox in the admin modal — the admin always has the final say per action.
- Seed default templates in a `FeatureRequestEmailTemplateSeeder`, following `EmailTemplateSeeder`.
- Testable end-to-end through the Mailpit container already in `docker-compose.yml`.

## 8. Changelog integration

When a request moves to `completed`, the admin can:
- **Link** it to an existing `Feature` (What's New) entry, or
- **Create** one from the request in a single click — title/description prefilled, `release_date` = today.

The board then renders a "Shipped in <feature title>" line on the completed card, and the public `Feature` API (`/api/public/apps/{app}/features`) can expose `requested_by_count` — closing the loop from request → vote → ship → announce.

---

## 9. Delivery phases

| Phase | Work | Est. |
|---|---|---|
| **1. Data layer** | 5 migrations, `FeatureRequest` / `FeatureRequestVote` / `FeatureBoard` / `FeatureRequestStatusLog` models + relations on `App`/`Installation`/`Feature`, factories, demo seeder | 0.5 day |
| **2. Board auth** | Key generation + rotation, `VerifyBoardToken` + `BoardSession` middleware, `POST /session`, shop normalisation & installation resolution, unit tests for signature/expiry/tamper | 1 day |
| **3. Board API** | `BoardController` + `BoardRequestController`: config, list, roadmap, show, submit, vote/unvote, `/me`; visibility rules, sorting (votes/newest/trending), rate limits, duplicate hint | 1 day |
| **4. Admin API** | `FeatureRequestController` + `BoardSettingsController`, ACL seeder entries, stats endpoint, status transitions writing logs | 1 day |
| **5. Admin UI** | `FeatureRequestsPage` (kanban + table + drag & drop), detail drawer with voter list, `BoardSettingsPage` with embed snippet, service layer, sidebar/router, dashboard widget | 2 days |
| **6. Public board UI** | `BoardLayout` + requests view + roadmap view, submit form, vote interactions, theming, embed mode, iframe height messaging, CSP/nginx config | 2 days |
| **7. Emails + changelog** | Template types + seeder, queued jobs, notify toggles, link/create-Feature flow | 1 day |
| **8. Integration guide** | `docs/board_integration_guide.md`: token-signing snippet (Laravel + Node), iframe embed, config keys, troubleshooting — matching the style of `connect_app_feature_implementation_guide.md`. Plus manual QA against a real app. | 0.5 day |

**Total ≈ 9 working days.** Phases 3–4 can run in parallel with 5–6 if you want to split frontend/backend.

A useful early milestone: after phases 1–3 the board is functional via API/curl, so you can validate the auth handshake with one real Shopify app before any UI is built.

---

## 10. Risks & mitigations

| Risk | Mitigation |
|---|---|
| `Feature` vs `FeatureRequest` confusion in code and UI | Distinct model/route/permission names throughout; admin sidebar labels "What's New" vs "Feature Requests" |
| `votes_count` drifting from the votes table | Every vote mutation runs inside a transaction with `increment`/`decrement`; a `board:recount` artisan command to repair |
| Iframe blocked by browser/Shopify | Explicit `frame-ancestors` allowlist per board, no `X-Frame-Options: DENY` on board routes, `SameSite=None; Secure` avoided entirely by keeping the session in `sessionStorage` rather than cookies |
| Vote manipulation | Server-side identity from an HMAC token only the app's backend can mint; unique constraint on `(request, voter_key)`; IP recorded for forensics |
| Slug collisions across apps | Unique constraint + auto-suffix on generation |
| Notification spam on rapid status changes | `notified_at` on status logs + per-action notify toggle |
| Board API is public — enumeration/scraping | Read-only data is already public by design; rate limits protect the DB; internal fields (`admin_note`, voter identities, emails) are never in board responses |
| Uninstalled stores retaining votes | `submitter_shop_domain` denormalised; votes survive; optionally filter roadmap counts by active installations in the admin view |

---

## 11. Out of scope for v1 (queued for v2)

- Comments and threaded admin replies
- Duplicate detection + merge (carrying votes across)
- Realtime vote updates over the existing Reverb container
- Plan/revenue-weighted vote scoring (`weight` column is already reserved)
- Public roadmap RSS/JSON feed and per-request permalinks with OG tags
- CSV export of requests + voters
