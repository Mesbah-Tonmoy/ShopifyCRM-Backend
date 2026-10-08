# SMTP Setup (platform sender per connected app)

Lets an operator pick, from the CRM, which SMTP service a connected Shopify app
sends its **platform** mail through — the transport behind that app's default
email option and all of its system mail (install, uninstall, spam alerts,
billing thresholds). Previously each app had one provider hardcoded (FormCRM had
Mailtrap inlined in `sendMailtrapEmail`), so switching meant a code change and a
deploy.

Merchant-supplied SMTP (a store's own Gmail/Zoho/Outlook/SendGrid credentials)
is unrelated and untouched — that still lives in the app's own settings.

## Where the data lives

The provider table lives **in the app**, not in the CRM. The app is what sends
the mail and what has to keep working when the CRM is unreachable, so its own
database is the single source of truth. The CRM stores no copy; its page is a
proxy, which is why the page needs the app online to load.

```
CRM frontend  →  CRM backend (SmtpProviderController)  →  {app_url}/api/smtp-providers
                        Bearer config('webhook.secret')       Bearer CRM_WEBHOOK_SECRET
```

The secret is the same one `/api/sync-crm` and `pushPlans` already use: the
CRM's `WEBHOOK_SECRET` must equal the app's `CRM_WEBHOOK_SECRET`.

## Resolution order in the app

The app builds a **chain**, best first, and a send walks it:

1. The `SmtpProvider` row with `isActive = true` and `isEnabled = true`.
2. The remaining `isEnabled` rows, in `priority` order (lower first).
3. The `SMTP_*` environment variables, then the legacy `MAILTRAP_*` ones.

The env entry is skipped when a provider row already resolves to the same host
and credentials, so the chain never retries an identical transport. A row whose
password will not decrypt is skipped with a warning rather than attempted
unauthenticated.

So a fresh deploy with an empty table still sends.

## Failover

A send that fails for a **transport** reason is retried on the next entry in the
chain: connection refused, socket/TLS error, timeout, rejected credentials
(`EAUTH`, 530, 535), any 4xx, and SES throttling (454). A send that fails
because the **message** is unacceptable — unknown recipient, mailbox full,
message too large — is not retried, since every provider would reject it the
same way and retrying only multiplies the bounce.

One deliberate exception: `554 … address is not verified` is retried, because
SES verifies identities *per region* and the standby region may have the
identity the primary lacks.

Known tradeoff: a timeout that arrives after the server already accepted the
message is indistinguishable from one that arrives before, so a retry could
deliver twice. That is rarer than an outage silently dropping mail, so the retry
wins — which is why the retry list is an allowlist and not "retry everything".

Every attempt is recorded in the send result's `details.attempts`, and lands in
`LeadsActivityLog` for merchant mail. `details.failedOver` is true when a
backup delivered; `details.exhausted` is true when the whole chain failed.

### Two-region SES

Add one provider per region — e.g. `ses-us-west-1` (active) and `ses-us-east-1`
(priority 20). Each needs its own endpoint, SMTP credentials and configuration
set. SES SMTP passwords are region-derived, so credentials issued for one region
fail with `535` against another region's host.

Identity verification and production (non-sandbox) access are **per-region** in
SES: a domain verified in California does not count in Virginia. Verify in both,
or the standby will reject everything the moment you fail over.

The CRM page shows the whole chain, primary first, under "Currently sending
through" / "Failover order".

A second region buys nothing for per-store mail until it also has tenants in it:
provisioning targets the active region only, and a failover into a region where a
store has no tenant cannot send. See *Moving every store to another region*
below, or use "Fill this region" on the standby's card.

Passwords are stored AES-256-GCM encrypted in the app's database and never
returned — reads give a mask plus a `hasPassword` flag. An edit form that
submits an empty password field keeps the stored one; sending an explicit empty
string clears it.

## CRM surface

| Piece | Location |
| --- | --- |
| Page | `frontend/src/views/SmtpProvidersPage.vue` (route `/smtp-setup`) |
| API client | `frontend/src/services/smtpProviderService.ts` |
| Proxy | `backend/app/Http/Controllers/Api/SmtpProviderController.php` |
| Routes | `backend/routes/api.php` — `/apps/{app}/smtp-providers*` |
| Permissions | `smtp.view`, `smtp.edit` (in `ACLSeeder`) |

Run `php artisan db:seed --class=ACLSeeder` after deploying so the two new
permissions exist and the full-access roles pick them up.

## App-side surface (per app that supports this)

| Piece | Location |
| --- | --- |
| Table | `SmtpProvider` in `prisma/schema.prisma` |
| Resolver | `app/service/smtp-provider.server.ts` (`getSmtpChain`, `isRetryableSendError`) |
| Encryption | `app/util/secretBox.server.ts` |
| Send path | `sendPlatformEmail` in `app/service/email.service.server.ts` |
| Endpoint | `app/routes/api.smtp-providers.ts` |

App environment variables:

```
SMTP_ENCRYPTION_KEY=   # optional; falls back to SHOPIFY_API_SECRET
SMTP_HOST=             # optional env fallback, used when no provider is active
SMTP_PORT=587
SMTP_SECURE=false
SMTP_USER=
SMTP_PASS=
SMTP_FROM=
SMTP_FROM_NAME=
SMTP_CONFIGURATION_SET=   # SES only, for the env fallback
```

## Notes

- Only one provider is active at a time; activation clears the flag on the rest
  in one transaction. The other enabled providers stay as failover targets.
- `configurationSet` is emitted as the `X-SES-CONFIGURATION-SET` message header,
  which is what turns on SES event publishing (bounces, complaints, deliveries)
  and any dedicated IP pool. Non-SES providers ignore the header.
- The app caches the resolved config for 60s, so a change made in the CRM takes
  effect within a minute on every instance behind the load balancer.
- **Test** opens and authenticates an SMTP connection without sending anything,
  and records the result on the row.
- A provider's *From email* only fills in where the app supplied none. The
  app's own sender wins by default, because its call sites use several distinct
  system addresses. Tick *Force the From email* (stored as
  `extra.overrideFrom`) when a service will only accept senders on its own
  verified domain.
- Deleting the active provider is allowed and drops the app to the environment
  fallback, which still sends. The response says so.

## SES tenants (by plan)

Without tenants, one store with a bad list pushes the whole SES account's bounce
rate over the threshold and pauses sending for every store. A tenant gives a
store its own reputation profile, its own metrics, and its own sending status, so
a problem store can be stopped on its own.

AWS bills per tenant per month, so only paying stores get one of their own:

| Store | Tenant | Row `tenantType` |
| --- | --- | --- |
| Premium — paid plan, trial, cancelled but inside its paid period, lifetime, or a plan granted in the admin panel | Its own **dedicated** tenant | `dedicated` |
| Free plan | The shared **free pool** tenant, `__free__` (`FREE_POOL_SHOP`) | `free` (owns nothing in AWS) |
| — | The free pool itself | `pool` |
| — | Our own operational mail, `__platform__` | `platform` |

See *Tenants by plan* and *Daily check* below for how stores move between the
two and how the status is watched.

Verified against the SES docs, because these shape the whole design:

| Fact | Consequence here |
| --- | --- |
| SMTP header is `X-SES-TENANT` | Set per send, next to `X-SES-CONFIGURATION-SET` |
| Tenants are region-scoped, never replicated | A tenant exists only where it was created. Provisioning targets **one** region (the active one); a second region is opt-in, and failover can only land where a tenant exists |
| A tenant cannot send until an identity **and** a configuration set are associated | Provisioning does the associations, not just the create. The IAM key needs `ses:CreateTenantResourceAssociation` on **both** ARNs - a policy listing only the identity fails on the configuration set |
| Name: max 64 chars, alphanumerics/`-`/`_` only | Shop domains have dots, so the name is derived, not copied |
| AWS charges per tenant per month | One tenant per **premium** store per region, plus the pool and platform tenants |

### Credentials

Tenant operations are SES **API** calls and SMTP credentials cannot make them, so
each provider row carries its own IAM key pair (`awsRegion`, `awsAccessKeyId`,
`awsSecretAccessKey` encrypted, plus the identity and configuration-set ARNs).
The key needs `ses:CreateTenant`, `ses:CreateTenantResourceAssociation`,
`ses:GetTenant`, `ses:DeleteTenant` and
`ses:UpdateReputationEntityCustomerManagedStatus`.

Both gates must allow these: the identity policy **and** any permissions
boundary on the user. IAM reports only the first gate that fails, so a boundary
block looks like a policy problem until the policy is fixed.

### Naming

`tenantNameFor(shop)` strips `.myshopify.com`, sanitises the rest, and appends an
8-char hash of the full domain. The hash is not decoration: sanitising is lossy,
so `a.b.myshopify.com` and `a-b.myshopify.com` would otherwise collide. It is
deterministic, so provisioning can be re-run safely.

### Provisioning

Two triggers, both idempotent (`AlreadyExistsException` counts as success):

- **New install** — `afterAuth` in `shopify.server.ts` adds the store to the
  free pool in the **active region only** (a database row, no AWS call), then
  runs the plan check in the background, which gives a store that is already
  premium its dedicated tenant. A failure is logged and left to the daily check;
  a store that cannot get a tenant is still installed.
- **Existing stores** — "Fill active region" on the CRM's SES Tenants page, or
  "Fill this region" on a specific region's card. Premium stores get (or keep) a
  dedicated tenant, free stores a pool row, and the pool and platform tenants are
  created if missing. Paged (25 stores at a time) and sequential, because SES
  answers bursts with `TooManyRequestsException`.

Every provisioning call takes an optional `providerKey`. Omitted, it means the
active region. Named, it targets that one — which is how a single store is moved
to another region, and how a whole region is filled ahead of a migration.

There is no lazy create on send, by choice: it would put an SES round trip on the
form-submission path.

### Which mail carries a tenant

All of it — but not all under the same tenant. A configuration set with
suppression scope `TENANT` makes SES reject mail that names no tenant, so "send
untenanted" is not an option for anything.

Merchant mail (auto-responses, admin notifications, resends) goes under that
store's dedicated tenant, or the free pool for a free-plan store. Our own operational mail (install/uninstall notices, spam alerts,
billing warnings) goes under a **platform tenant** of its own, `__platform__`
(`PLATFORM_SHOP`). It must not borrow a merchant's: pausing an abusive store
would otherwise silence the alerts about that store. Mechanically, the difference
is whether the call site passes `shop`.

### Pause and resume

`localStatus` (ours) and `sendingStatus` (mirrors AWS) are tracked separately.
A pause writes the local flag first, so it takes effect without waiting on AWS
and survives an API failure — the failure mode is "blocked here but not yet at
AWS", never the reverse. A reason is mandatory; it is stored with who asked and
when.

Pausing applies to **every tenant the store has**, not to one region. Normally
that is just the active region, since provisioning targets one. It stays unscoped
because a store that *does* have a second tenant — staged, or mid-migration —
must be stopped in both, or the next transport failure fails over into a region
where it is still enabled.

A paused store is refused before any SMTP attempt, and is *not* sent without a
tenant header — that would put its traffic back on the shared account reputation,
which is what pausing exists to prevent.

### AWS-initiated pauses

SES pauses tenants on its own via reputation policies (Standard by default) and
Trust & Safety. Two paths keep our view current:

- `POST /api/webhooks/ses-tenant-status` — wire as an EventBridge **API
  destination** (not SNS: an SNS HTTP subscription cannot set headers, which
  would force the secret into the URL). Rule pattern
  `{"source":["aws.ses"],"detail-type":["Sending Status Enabled","Sending Status Disabled","Email Bounced","Email Complaint Received"]}`,
  connection with an `Authorization: Bearer <SES_EVENT_SECRET>` header. The
  bounce/complaint types feed *Store reputation* below.
  The API destination must be a public URL: a local app behind a `shopify app
  dev` tunnel gets a new URL each run, so EventBridge cannot reach it unless the
  destination is updated. The rule's CloudWatch metrics tell the two failure
  modes apart — `TriggeredRules` 0 means AWS emitted nothing,
  `FailedInvocations` means it could not deliver.
- "Sync from AWS" on the tenants page, plus `intent: sync` — the safety net for
  events that never arrive.

An AWS pause of a **dedicated** tenant also sets that store's local flag. An AWS
*resume* does not clear a local pause: a reputation recovery should not undo an
operator's decision. An AWS pause of the **pool** or **platform** tenant does not
set a local flag — the `DISABLED` status already stops those sends, and lifts
itself when AWS reinstates; a local pause would leave every free store blocked
until someone resumed it by hand.

With real-time alerts on (the default), each status change the webhook applies
is also emailed to the daily check's recipients straight away.

### Surfaces

| Piece | Location |
| --- | --- |
| Table | `SesTenant` in `prisma/schema.prisma` |
| Service | `app/service/ses-tenant.server.ts` |
| Plan check | `app/service/ses-tenant-plan.server.ts` |
| Daily check, alerts | `app/service/ses-tenant-monitor.server.ts`, scheduled from `app/service/scheduler.server.ts` |
| Settings, run log | `AppSetting` (`sesTenantMonitor`), `ScheduledJobRun` in `prisma/schema.prisma` |
| Store reputation | `app/service/ses-store-stats.server.ts` (tags, counts), `app/service/ses-store-reputation.server.ts` (hourly check), `StoreMailStats` table |
| Endpoint | `app/routes/api.ses-tenants.ts` |
| EventBridge webhook | `app/routes/api.webhooks.ses-tenant-status.ts` |
| CRM proxy | `backend/app/Http/Controllers/Api/SesTenantController.php` |
| CRM page | `frontend/src/views/SesTenantsPage.vue` (route `/ses-tenants`) |
| Permissions | `ses_tenants.view`, `ses_tenants.edit` |

App env vars, all optional:

- `SES_EVENT_SECRET` — falls back to `CRM_WEBHOOK_SECRET`.
- `SES_TENANT_ALERT_RECIPIENTS` — comma-separated default recipients for the
  daily check, used until someone saves recipients in the CRM.
- `CRM_APP_URL` — the CRM frontend's base URL, for the link in alert emails.

### No on/off toggle

There was once a `tenantsEnabled` column gating the `X-SES-TENANT` header. It was
dropped (`20260911180004_smtp_drop_tenants_toggle`). With suppression scope
`TENANT`, SES rejects any message that names no tenant, so a provider holding AWS
credentials always sends the header — a toggle would only have offered a setting
that breaks all mail.

### Tenant lifecycle

| Event | What happens to the tenants | Where |
| --- | --- | --- |
| Install | Added to the **free pool** in the active region; plan check runs in the background | `afterAuth`, `shopify.server.ts` |
| Reinstall | Pool row created if missing; an **automatic** pause is lifted; plan check | `afterAuth` |
| Plan becomes premium (incl. trial start, admin grant) | **Dedicated** tenant created in the active region | Subscription webhook, purchase confirmation, admin panel, daily check |
| Plan ends (period over, expired, declined, frozen, grant removed) | Moved to the **pool**, dedicated tenant **deleted** | Subscription webhook, daily check |
| Uninstall | Dedicated tenant **deleted**; pool row **paused**, marked `system:uninstall` | `APP_UNINSTALLED`, `webhooks.tsx` |
| Shop redact (~48h after uninstall) | Rows **deleted**, and any tenant still left | `SHOP_REDACT` + `webhooks.shop.redact.jsx` |
| Operator deletes | Deleted in the region shown, AWS charge stops | CRM → Delete |
| Operator replaces (any tenant, including the free pool) | New one created and linked under a new name; the row switches once it can send; then the old one is deleted (retried daily if AWS refuses) | CRM → Replace |
| Operator adds a region | A second tenant created, nothing deleted | CRM → Add region |

Uninstall deletes a dedicated tenant straight away because Shopify cancels the
app's subscription with the uninstall: a reinstall starts on the free plan, and
if it comes back premium the plan check recreates the tenant under the same
deterministic name. The pool row is paused rather than deleted so a reinstall
inside the 48-hour window picks up where it left off.

Reinstall lifts only pauses whose `pausedBy` starts with `system:`. An operator's
abuse pause, or one AWS applied for reputation, survives — otherwise
uninstall-then-reinstall would be a way to clear a suspension.

Shop redaction is handled in two places, because which URL Shopify calls depends
on how the compliance webhook is configured. Both are idempotent and both run in
the background, since Shopify wants a response within 5 seconds.

### Deleting and re-assigning

Deletion removes our row **only when AWS confirms**. A failure leaves the row
visible carrying the error, rather than an orphaned tenant that still bills every
month and that nothing in the UI would mention again. The `force` checkbox drops
the row anyway, for when the tenant was already removed in the AWS console and
only our record remains.

A store with no tenant appears under **Stores without tenants** on the tenants
page, each with a *Create tenant* button — after a delete, that list is the only
place the store shows up.

Re-creating uses the deterministic name, so it repairs rather than renames.
*Replace* is the opt-out: it creates a tenant with a random suffix appended, for
when the old tenant's history is unwanted. It is make-before-break: the old
tenant keeps sending until the new one is created, linked and sendable, and is
deleted only after the switch. If the new one cannot be made sendable, it is
removed and the old one stays in use. An old tenant AWS refuses to delete is
kept in `retiredTenantName` and retried daily, so nothing is left billing
unnoticed.

Replacing the **free pool** moves every free store at once and does not
interrupt their mail. If AWS disabled the pool for reputation, pause the stores
that caused it first (see *Store reputation*): a fresh tenant does not reset the
account-level bounce and complaint rates AWS also watches, and the same mail will
get the new tenant disabled too.

### Moving every store to another region

Tenants are region-scoped and never replicated, so "changing region" means
creating a second set of tenants, switching sending to them, and deleting the
first set. The CRM drives it from **SES Tenants → Move region** (visible only
when more than one region is configured), which reads
`intent: migrationStatus` and shows the three steps with live counts.

Before starting, the target region needs, in the SES console: the sending domain
verified, a configuration set with the **same event destinations** (or the
tenant-status webhook stops reporting AWS-side pauses), production access if the
account is still in the sandbox, and an IAM policy covering that region's
identity **and** configuration-set ARNs.

| # | Step | UI | API |
| --- | --- | --- | --- |
| 1 | Fill the target region | *Provision N store(s)* | `provisionAll` + `providerKey`, paged |
| 2 | Switch sending to it | *Switch to \<region\>* | `smtp-providers` → `activate` |
| 3 | Empty the old region | *Delete N tenant(s)* | `deleteRegion` + `providerKey`, paged |

**Step 2 is gated.** The button stays disabled until the app reports
`readyToCutOver` — every active store has a tenant in the target, every one of
them is sendable (`ENABLED`/`REINSTATED` **and** `resourcesLinked`), and the
provider has both ARNs. Cutting over onto a half-filled region does not fail
loudly; the stragglers simply stop sending, and nobody finds out until a merchant
complains.

**Step 3 refuses the active region** unless `allowActive` is passed, and the UI
makes you type the region name. Emptying the region you are sending from stops
all mail, because SES rejects a send that names no tenant.

#### What the app cannot do for you

Three things sit outside this codebase entirely. The migration panel lists them
above the cutover button, and the confirmation dialog will not proceed until you
tick that the first is done.

- **The account-level suppression list is per-region and is not replicated.**
  Nothing here syncs it. Cut over without copying it and every address that
  previously hard-bounced becomes sendable again — you re-send to dead addresses
  and take the bounce-rate hit in the region that has no reputation history to
  absorb it. Export from the old region, import to the new, *before* step 2.
- **Reputation does not move.** New tenants in a new region start with none, and
  the region has its own sending quota and warm-up. Ramp volume; do not cut over
  cold at full send rate.
- **Event destinations** on the new configuration set must match, or
  `POST /api/webhooks/ses-tenant-status` goes quiet and AWS-initiated pauses stop
  reaching us until someone runs *Sync from AWS*.

#### Moving a single store

*Add region* on a table row creates a tenant for that one store in another
region, deleting nothing. Follow with *Delete* on the old region's row once mail
is confirmed flowing. This is also how a region is staged for one store before
committing to the whole estate.

#### Rollback

Step 2 is reversible on its own: activate the old provider again, as long as
step 3 has not run. After step 3 the old region has no tenants and rolling back
means refilling it — step 1 in the other direction. Keep the old region's
tenants until the new one has been sending cleanly for a while; the monthly
per-tenant charge is the price of a cheap rollback.

### Which tenant a send uses

`resolveTenantForSend(shop, providerKey)` picks, in order:

1. The store is paused locally → `blocked`.
2. A **dedicated** row that SES disabled → `blocked`. A reputation verdict on
   that store is not routed around through the pool.
3. A **dedicated** row that is `ENABLED`/`REINSTATED` → `send` under it.
4. Anything else — a free store, a store with no row, a premium store whose
   tenant is `PENDING`/`ERROR`/`MISSING` → the region's **pool**: paused by an
   operator → `blocked`; not sendable → `unavailable`; otherwise `send` under it.

No `shop` (our own mail) uses the platform row the same way.

| Kind | Meaning | Send path |
| --- | --- | --- |
| `send` | A usable tenant in this region | Sends, naming it |
| `blocked` | A **decision** — the store (or the whole pool) is paused, or SES disabled the store's own tenant | Stops. Does not try another region: the same decision applies there |
| `unavailable` | A **fault** — no pool/platform tenant here, or it is not sendable | Moves down the failover chain; another region may work |

There is no "send without a tenant" outcome. Under suppression scope `TENANT`,
SES rejects untenanted mail outright. So the pool and platform tenants missing is
an outage; a premium store missing its dedicated tenant is degraded (it sends
under the pool). Both appear in the CRM's "needs attention" list.

## Tenants by plan

`app/service/ses-tenant-plan.server.ts` keeps each store's tenant in line with its
plan. "Premium" is whatever `getSubscription` returns as not free — the same
answer that decides the store's features — so a cancelled store keeps its
dedicated tenant exactly as long as it keeps its paid features.

| Trigger | When |
| --- | --- |
| `APP_SUBSCRIPTIONS_UPDATE` | `ACTIVE` (incl. trial start) is premium without a lookup; every other status is checked live |
| Purchase confirmation page | The merchant lands back after approving a charge |
| Install / re-auth | In the background after `afterAuth` |
| Admin panel plan grant set / reviewed / removed | Immediately |
| CRM → **Check plan** on a row | On demand |
| Daily check | Every store, every day — catches what events miss, including a cancelled plan's period running out, which sends no webhook |

The plan lookup is **strict**: `getSubscription(auth, { strict: true })` throws on
a GraphQL error instead of answering FREE, because here FREE deletes a tenant.
A failed lookup changes nothing and is reported.

Downgrade switches the row to the pool **first**, then deletes the dedicated
tenant, so the store keeps sending throughout. If AWS refuses, the old name stays
in `retiredTenantName`, the CRM marks the row "old tenant pending delete", and the
daily check retries. A store SES had disabled is kept paused locally after the
downgrade, so dropping to free cannot clear a reputation pause.

Upgrade creates the dedicated tenant under the deterministic name. Until it is
sendable, the store sends under the pool.

## Daily check

Once a day, at a time set in the CRM (**SES Tenants → Daily check**; default
09:00 Asia/Dhaka), the app:

1. Makes sure the pool and platform tenants exist and are linked in the active
   region.
2. Runs the plan check for every installed store.
3. Retries deleting tenants given up on downgrade.
4. Syncs every tenant from AWS (the same as "Sync from AWS", which stays).

If anything changed or failed, the recipients get one email: status changes
(before → after), plan moves, and problems, with changes to the pool or platform
tenant flagged at the top as **ACTION NEEDED**. A check that fails also emails.
"Send the daily email even when nothing changed" turns it into a heartbeat.

Scheduling: every app instance ticks once a minute. The run is due once the
local time passes the configured time, unless a scheduled run already happened
today at or after that time — so moving the time later after today's run (09:00
→ 16:00) runs again at 16:00, and moving it earlier does not repeat the day.
Manual runs do not count. The `ScheduledJobRun` row (`runKey` = local date and
time slot, unique per job) is the lock, so only one instance runs it. If the app
is down at the set time, the run happens when it is back, the same day.

**Run now** starts it in the background; the panel polls and shows the last ten
runs. Alerts go out through the platform SMTP chain under the platform tenant,
falling back to Mailgun when the chain itself cannot send.

| Setting | Meaning |
| --- | --- |
| Run every day | Off stops the scheduled run; Run now still works |
| Time, time zone | Local time the run becomes due |
| Recipients | Up to 20 |
| Real-time alerts | Email each AWS status change from EventBridge as it arrives |
| Email when unchanged | Daily email even with nothing to report |
| Store reputation | See below |

## Store reputation (hourly)

Every free store sends under the one pool tenant, so AWS judges — and disables —
the pool on the free stores' *combined* bounce and complaint rates. One store
with a bought list can take every free store's mail down. The app watches each
store's own rates and stops it first.

How a store's rates are known:

1. Merchant mail through SES carries `X-SES-MESSAGE-TAGS: store=<Stores.id>`
   (tag values cannot contain dots, so not the domain).
2. The app counts recipients per store per hour as mail is accepted
   (`StoreMailStats.sent`).
3. SES reports bounces and complaints to EventBridge with the message's tags; the
   webhook counts **permanent** bounces and complaints against the tagged store.

Every hour (one instance, locked per hour) each store's last 7 days are compared
with the limits set in the CRM (Daily check → Store reputation):

| Store | Over the warning limit | Over the pause limit |
| --- | --- | --- |
| Free | Email (once a day per store) | **Paused** (only that store; `pausedBy: reputation`) + email |
| Premium | Email | Email only — its mail only hurts its own tenant |

Defaults: warn at 2% bounce / 0.05% complaints, pause at 4% / 0.08%, ignoring
stores under 50 recipients. A pause also needs at least 3 hard bounces or 2
complaints behind the rate, so one spam click cannot pause a small store.
Bounces and complaints are counted in the hour the mail was sent, not when the
report arrived — inside AWS's review line of 5% / 0.1%. A reinstall
does not lift a reputation pause. Resuming in the CRM restarts the store's
7-day window, so a reviewed store is judged on what it sends next. The tenants
table shows each store's 7-day numbers (or "since resume"), coloured against the
limits, with "(too few to judge)" under the minimum. Status → **At risk** lists
only stores over a warning or pause limit, and Sort orders by bounce rate,
complaint rate or volume. Filtering, sorting and paging (25–200 stores per page)
all happen in the app, so they cover every store, not just the loaded page.

**AWS setup this needs**, per region:

1. On the SES **configuration set** used for sending, add an **event destination**
   of type **Amazon EventBridge** (default event bus) with event types
   **Bounce** and **Complaint**.
2. Add `Email Bounced` and `Email Complaint Received` to the EventBridge rule's
   `detail-type` list (see *AWS-initiated pauses*). Same API destination.

Without these, sends are counted but no bounces arrive, so every store reads 0%.

## Credential encryption and key rotation

Two values are encrypted at rest: each provider's SMTP password and its AWS
secret access key. Everything else — host, port, username, access key id, ARNs,
tenant names — is an identifier, not a credential, and is stored as-is.

```
sealSecret(plaintext):
  key    = sha256(SMTP_ENCRYPTION_KEY)      # 32 bytes
  iv     = randomBytes(12)                  # fresh per write
  stored = "v2:" + kid + ":" + base64(iv ‖ authTag ‖ ciphertext)
```

AES-256-GCM, so one primitive covers both secrecy and tamper detection — a
modified row fails its auth tag rather than decrypting to garbage. A fresh IV per
write means the same password sealed twice produces different ciphertext.

`kid` is an 8-hex fingerprint of the key material (domain-separated and
truncated, so it cannot be used to attack the key). It is what makes a rotation
*finishable*: without it, a half-rotated table is indistinguishable from a
finished one.

### What this protects against

A database dump, a backup, or a read-only replica leak. It does **not** protect
against code execution on the app server — the key is in that process's
environment. KMS or Secrets Manager is the upgrade path if that threat matters.

### Rotating the key

```bash
openssl rand -base64 32
```

1. Move the current value of `SMTP_ENCRYPTION_KEY` to
   `SMTP_ENCRYPTION_KEY_PREVIOUS` (comma-separated; more than one is allowed),
   put the new key in `SMTP_ENCRYPTION_KEY`, deploy.
2. CRM → SMTP Setup → **Re-encrypt secrets**. Values are read with the whole
   ring and rewritten under the new key. Idempotent, and safe to run while both
   keys are configured — which is what avoids a window where credentials are
   unreadable.
3. When the panel shows `0 under an older key`, remove
   `SMTP_ENCRYPTION_KEY_PREVIOUS` and deploy again.

Skipping step 2 is safe but leaves the old key load-bearing: drop it then and
those secrets become unreadable.

The SMTP Setup page shows the current key id and the counts — current, stale,
unreadable — so rotation progress is visible rather than assumed. A secret no key
can open is reported by provider and field rather than overwritten, because
overwriting would destroy it; the fix is to paste the credential in again.

`SHOPIFY_API_SECRET` still works as a last-resort reader so rows written before
`SMTP_ENCRYPTION_KEY` existed keep opening, and the page warns while it is the
only key configured — one secret shared between form tokens and stored
credentials means one leak costs both.

### Deferred

An append-only audit trail for tenant pause/resume/delete was discussed and left
for later. Today only current state is kept (`pausedBy`, `pausedReason`,
`pausedAt`); a delete removes the row and leaves no trace of who did it.
