# Feature Request Board — handoff

Context for picking this work up in a fresh session. The plan document is
`docs/FEATURE_REQUEST_BOARD_PLAN.md`; this file records what was actually built,
the decisions behind it, and what is still open.

## What it is

A features.vote-style feature request board with per-store voting, served by the
CRM and embedded by each Shopify app in an iframe.

Locked decisions:

- **Delivery** — hosted board page at `/board/{slug}`, embedded in an iframe.
- **Voter identity** — one vote per shop domain (per installation).
- **API auth** — per-app `board_public_key` + `board_secret`; the Shopify app
  signs a short-lived HMAC token server-side, the board trades it for an opaque
  encrypted session (`Authorization: Board <session>`).
- **Moderation** — off by default (`require_approval = false`). Per-request
  `is_hidden` is the explicit override.
- **Scope** — submit, vote, comment, follow, 5 status columns, attachments,
  email notifications, changelog linking.

### Why it is not built with Polaris web components

Polaris web components (`s-*`) are registered by App Bridge, which is designed
for the app's own top-level frame. A nested cross-origin iframe has no App
Bridge, so those elements would never upgrade.

Porting the board to a CRM-hosted script bundle rendering `s-*` elements in the
host app's DOM was considered and **rejected**: Kaching Cart is Built for
Shopify and embeds features.vote in an iframe, which is direct evidence the
pattern passes review. The iframe stays; it is themed to match the admin
instead.

## Architecture

Laravel 12 on a **legacy skeleton** — `app/Http/Kernel.php` is authoritative,
not `bootstrap/app.php`. Frontend is Vue 3 `<script setup>` + Pinia + Vue Router
+ Tailwind v4.

### Backend

| Area | Location |
| --- | --- |
| Status lifecycle (single source of truth) | `app/Enums/FeatureRequestStatus.php` |
| Token / session handling | `app/Services/Board/BoardTokenService.php` |
| Submit, status change, moderation | `app/Services/Board/FeatureRequestService.php` |
| Voting | `app/Services/Board/VoteService.php` |
| Comments | `app/Services/Board/CommentService.php` |
| Public endpoints | `app/Http/Controllers/Api/Board/` |
| Session middleware (`board.session`, `:optional`) | `app/Http/Middleware/ResolveBoardSession.php` |
| Notification fan-out | `app/Listeners/SendFeatureRequestStatusNotification.php` |
| One email per recipient | `app/Jobs/SendFeatureRequestEmail.php` |
| Routes | `backend/routes/api.php` (`board` prefix) |
| Config | `backend/config/board.php` |

`FeatureRequest` carries the query composition: `withBoardPayload()`,
`visibleTo()`, `sortedBy()`, `search()`. **Always load a request for the board
through `withBoardPayload()`** — three separate bugs (comment count resetting,
store name vanishing) all came from refreshing a model and losing its
aggregates.

### Frontend

| Area | Location |
| --- | --- |
| Theme tokens | `frontend/src/assets/css/board.css` |
| Shell, tabs, submit button, toast | `frontend/src/layouts/BoardLayout.vue` |
| Shared board state | `frontend/src/composables/useBoardContext.ts` |
| Optimistic voting | `frontend/src/composables/useBoardVoting.ts` |
| Overlay sizing inside a grown iframe | `frontend/src/composables/useVisibleViewport.ts` |
| Tabs | `frontend/src/views/board/` |
| Components | `frontend/src/components/board/` |
| API client, sort options | `frontend/src/services/boardService.ts` |
| Admin UI | `frontend/src/views/FeatureRequestsPage.vue`, `BoardSettingsPage.vue` |

Routing: `/board/:slug` renders the **roadmap** (bare path, so existing embeds
land on it), `/board/:slug/requests` the list.

The submit form lives in `BoardSubmitModal.vue`, hosted by the layout so either
tab can raise it. Results reach the tabs through `lastCreated` / `lastUpdated`
on the board context.

## Theming

`board.css` copies values out of `@shopify/polaris-tokens` **9.4.2**, each one
commented with the token it came from. It is a copy, not a reference: the board
is a separate document, so nothing cascades in from the admin.

- `--bd-accent` is a **fill colour only** (`#303030`), paired with
  `--bd-accent-on`. Never use it for text.
- `--bd-link` (`#005bd3`) is interactive text.
- `--bd-input-*` are form-control surfaces and borders, distinct from cards.
- `.board-input`, `.board-btn` + `--primary`/`--secondary`, `.board-field`,
  `.board-badge`, `.board-error`, `.board-dropzone` and `.board-toast` are the
  shared control styles. `.board-modal__*` is the Polaris modal chrome, used by
  both modals: a tinted 16px header, hairline rules, and a close button that
  negates its own padding so it cannot make the header taller than the title.
  `BoardSelect.vue` and `BoardBanner.vue` mirror Polaris' Select and Banner,
  the latter picking its tone and icon from the request's status. All of it is
  copied from Polaris' own component CSS, not approximated.
- Tone aliases (`--bd-info`, `--bd-caution`, `--bd-positive`, `--bd-critical`)
  point at the status tokens, so a banner never borrows a status name.
- Dark mode is one block keyed off `data-theme`, which `BoardLayout` always
  stamps. Polaris' dark theme is experimental and partial — entries it defines
  are verbatim, the rest are marked `derived`.

**Inter is self-hosted**, as `@fontsource-variable/inter`, imported by
`BoardLayout.vue` so the face only loads on board pages — the CRM admin keeps
Hind Vadodara (`frontend/index.html`). It has to be the *variable* font:
Polaris' 550 and 650 weights exist in no static face, and a fallback rounds them
up, rendering every button and title a step bolder than the admin around it.
Not Google Fonts: the board is already a nested iframe, and a third-party font
host adds a DNS lookup and TLS handshake before text settles. Only the subset a
merchant's text needs is downloaded (latin is ~48KB).

## Running things

```bash
docker compose up -d                  # app, nginx, mysql, frontend, queue, reverb, mailpit
docker compose exec app php artisan board:token <slug> <shop.myshopify.com> --ttl=300
docker compose exec app php artisan test
docker compose exec frontend npx vue-tsc --noEmit -p tsconfig.app.json
docker compose exec frontend npx eslint src/views/board src/components/board
```

`QUEUE_CONNECTION=database` locally and in production, with a `queue` service in
compose. This matters: on `sync`, every notification was sent over SMTP inside
the HTTP request, which made a status change take ~9.5s and look frozen.

Tests run against a separate `shopify_crm_testing` database (set in
`phpunit.xml`) so `RefreshDatabase` never touches development data. Fixtures are
in `tests/Concerns/BuildsBoardFixtures.php`.

## Gotchas already paid for

- MySQL caps index names at 64 characters; name them explicitly in migrations.
- `JsonResource::collection()` breaks if you override the constructor — the
  board resources use a fluent `->forBoard()` setter instead.
- `EmailTemplateService` swallows its own exceptions and returns `false`.
  `SendFeatureRequestEmail` throws on `false` so failures reach `failed_jobs`.
- `position: fixed` and `vh` resolve against the iframe, not the screen. Overlays
  use `useVisibleViewport`.
- SVG uploads were removed from the allowed mime types (stored XSS); nginx also
  serves `/storage/` with `nosniff` and a sandbox CSP.
- `nginx client_max_body_size` is 6M — the 5MB upload limit is enforced above it.

## What's New (changelog)

`whats_new` is merged. It stays **separate** from the board: an update can ship
without anyone having asked for it, so `Feature` has no link to
`FeatureRequest` and none is planned. Admin screen at `/features`, public
read at `/api/public/apps/{id}/features`.

That endpoint sends CORS headers only for the CRM's own origin, so an embedding
app must fetch it **server-side**.

## Open items

- **Production `frame-ancestors`** on whatever serves the built SPA. The header
  is in `docs/board_integration_guide.md`; nothing sets it outside dev.
- **Webhook endpoints are unauthenticated** (`/api/webhooks/install`,
  `/uninstall`, `/plan-change`). Pre-existing. Anyone who knows an `app_url`
  can create or overwrite installation rows, including the email address board
  notifications are sent to. Fixing it means adding a shared secret to both the
  CRM and every Shopify app, so it needs sequencing rather than a quiet patch.
- **`v-html` in `EmailTemplatesPage.vue`** renders admin-authored template
  bodies unescaped. Admin-to-admin only, but it is the one unescaped sink.
- **Iframe auto-resize** — the board posts `zapio-board:height`; the embed
  snippet has no listener and a fixed 900px height. Deliberately deferred.
- 64 pre-existing ESLint errors outside board and changelog code.
- **`docs/` at the repo root is untracked by either git repo.** These notes and
  anything else here exist only on this machine; the integration guide was put
  in `backend/docs/` for that reason.
