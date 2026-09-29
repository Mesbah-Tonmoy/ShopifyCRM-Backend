# CRM email providers (Integrations → Email delivery)

Chooses the service the **CRM's own** mail goes through: install, uninstall and
7-day follow-up emails, and feature board notifications — everything sent by
`EmailTemplateService`.

Not to be confused with **SMTP Setup** (`/smtp-setup`, `smtp_setup_feature.md`),
which configures each *connected Shopify app's* sender and lives in the app's
database. The two are independent.

## Behaviour

- Providers: **SendGrid**, **Mailtrap**, **Amazon SES**. Exactly one is active,
  or none — in which case mail goes through the `.env` mailer (`MAIL_*`).
- Selecting a provider in the list only shows its settings. Activating is a
  separate, confirmed step, and clears the flag on every other provider in one
  transaction.
- A provider can only be activated when its required fields are saved, and the
  active provider cannot be saved into an incomplete state.
- If the active provider is somehow incomplete at send time, the send falls back
  to the `.env` mailer and logs a warning, rather than dropping the mail.
- Secrets (SendGrid API key, Mailtrap password, SES secret key) are write-only.
  Responses list which are set (`secrets_set`), never the values; saving with a
  secret left blank keeps the stored one. Stored encrypted (`encrypted:array`).
- **Test connection** checks the form's current values, saved or not, and never
  sends mail. SMTP providers: connect, TLS, authenticate, hang up. SES:
  `GetAccount` (sending enabled, sandbox, quota) and `GetEmailIdentity`
  (identity verified in that region).
- The mailer is re-registered and purged from Laravel's mailer cache on every
  send, so a settings change reaches long-running queue workers immediately.

## Code

| Piece | Location |
| --- | --- |
| Provider contract | `backend/app/Services/Mail/MailProvider.php` (+ `SmtpMailProvider.php`) |
| Providers | `backend/app/Services/Mail/Providers/*Provider.php` |
| Registry / resolution | `backend/app/Services/Mail/MailProviderRegistry.php` |
| SES tenant | `backend/app/Services/Mail/Ses/SesTenantManager.php` |
| API | `backend/app/Http/Controllers/Api/MailProviderController.php` |
| Routes | `/api/integrations/mail-providers*` (`integrations.view` / `integrations.edit`) |
| UI | `frontend/src/components/integrations/MailDeliverySettings.vue`, `SesTenantPanel.vue` |
| Tests | `backend/tests/Feature/Mail/` |

The field schema is served by the backend, so **adding a provider** is one
`MailProvider` subclass registered in `MailProviderRegistry::__construct` — no
frontend change, no migration (the `integrations` row is created on first read).

## Amazon SES

Two ways to send, chosen by **Send via** on the page:

| | SES SMTP (default) | SES API |
| --- | --- | --- |
| Sends with | SES SMTP username/password against `email-smtp.<region>.amazonaws.com` | The IAM access key (Laravel's `ses-v2` transport) |
| Access key used for | Tenant management only; optional if you skip the tenant | Sending *and* tenant management |
| Config set & tenant travel as | `X-SES-CONFIGURATION-SET` / `X-SES-TENANT` headers | `ConfigurationSetName` / `TenantName` on `SendEmail` |
| Key needs `ses:SendEmail` | No | Yes |

SMTP mirrors how the connected apps send, so an app's existing SES SMTP
credentials and tenant-management key can be reused (e.g. FormCRM's). SMTP
credentials are region-derived: they only authenticate against the region they
were issued for. The tenant header is honoured by the classic endpoint used
here; see the open item in `smtp_setup_feature.md` about Mail Manager endpoints.

### IAM policy for the access key

With **SES SMTP**, only the tenant statement below is needed (and `ses:GetAccount`
/`ses:GetEmailIdentity` are optional — *Test connection* skips those checks with a
warning when denied). With **SES API**, the key needs the `Send` statement too.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "Send",
      "Effect": "Allow",
      "Action": ["ses:SendEmail", "ses:SendRawEmail"],
      "Resource": "*"
    },
    {
      "Sid": "CheckAndManageTenant",
      "Effect": "Allow",
      "Action": [
        "ses:GetAccount",
        "ses:GetEmailIdentity",
        "ses:CreateTenant",
        "ses:GetTenant",
        "ses:CreateTenantResourceAssociation",
        "ses:ListTenantResources",
        "ses:TagResource"
      ],
      "Resource": "*"
    }
  ]
}
```

`sts:GetCallerIdentity` (used to build ARNs) needs no permission. Scope
`Resource` down to the identity, configuration set and tenant ARNs once they
exist. If a permissions boundary is attached, it must allow these too.

Two of these are optional, and the page degrades rather than fails without them:

| Permission | Without it |
| --- | --- |
| `ses:TagResource` | The tenant is created untagged (tags only label it in the console). |
| `ses:ListTenantResources` | *Check AWS status* shows the tenant's status but not its associations. |

Use a **dedicated** IAM user for the CRM rather than reusing an app's key (e.g.
FormCRM's `formcrm-ses-tenant-manager`). That key is scoped to tenant management
only, because the app sends over SES SMTP, whereas the CRM sends through the API
with this same key and so also needs `ses:SendEmail` / `ses:SendRawEmail` and
`ses:GetAccount`. A shared key also means revoking one system revokes both.

### Tenant: one per environment

A tenant gives the CRM its own reputation, metrics and sending status inside the
SES account, so a bounce spike from CRM mail cannot pause the account's other
senders (e.g. the apps' per-store tenants), and vice versa.

The name is a **readable stem plus an unguessable suffix**:

```
shopify-crm-local-61da25d08a
└──── stem ─────┘└─ suffix ─┘
```

The stem comes from **`SES_TENANT_NAME`**, defaulting to `shopify-crm-${APP_ENV}`:

| Environment | `.env` |
| --- | --- |
| Local | `SES_TENANT_NAME=shopify-crm-local` |
| Production | `SES_TENANT_NAME=shopify-crm-production` |

It is deliberately an environment variable rather than a page setting: each
environment has its own database, but databases get copied, and a copied setting
would put local mail on production's reputation.

The suffix is derived from **`APP_KEY`** (`SES_TENANT_SUFFIX` overrides it). It
exists because a configuration set with suppression scope `TENANT` makes SES
reject any message that does not name a valid tenant — so a name nobody can
guess is one more thing an attacker holding only SMTP credentials would still
need. It is defence in depth, not a primary control: **the credentials are**. A
tenant name is not a secret to AWS, and anyone with IAM access to the account
can list every tenant in it.

It is *derived*, not random, because the name must be stable. A value that
changed per boot would orphan the previous tenant on every restart — still
billing, invisible to this page. Deriving from `APP_KEY` also makes environments
differ automatically, with nothing extra to configure.

> **Changing the stem, the suffix or `APP_KEY` renames the tenant.** Mail then
> goes out untenanted (never dropped) and the page says why, but the old tenant
> stays in SES and keeps billing monthly. Delete it in the SES console, then
> **Provision tenant** again.

**Provision tenant** on the page creates the tenant and associates the sending
identity and the configuration set — SES refuses tenant mail until both are
associated. It is idempotent (anything already existing counts as done), so it
also repairs a partial setup. **Check AWS status** reads the tenant and its
associations back and refreshes the stored sending status.

Mail carries the tenant only while what was provisioned still matches the
current tenant name, region, identity and configuration set. Change any of them
and sends go out **without** a tenant (never dropped) until you provision again;
the page says why. A tenant AWS has paused (`DISABLED`) is still named on sends,
so SES rejects them — sending untenanted would bypass the pause.

Tenants are region-scoped and billed per tenant per month.

### Setting up SES in an environment

1. In the SES console, **in the region you will use**: verify the sending domain
   (or address), create a configuration set, and request production access if
   the account is still in the sandbox.
2. Create an IAM user/key with the policy above.
3. Set `SES_TENANT_NAME` in that environment's `backend/.env` (leave
   `SES_TENANT_SUFFIX` blank to derive one from `APP_KEY`), then
   `php artisan config:clear` (or `optimize`). The panel shows the full name it
   will use — check it before provisioning.
4. Integrations → Email delivery → Amazon SES: pick **Send via**, fill in the
   region, SMTP credentials (SMTP) and/or access key, configuration set and From
   email → **Save** → **Test connection**.
5. **Provision tenant** → confirm the panel shows *In use*.
6. **Activate**.

## Deploying

The pipeline's `composer install` pulls in `aws/aws-sdk-php` (new dependency),
and `migrate` runs `2026_09_23_000001_enforce_single_active_mail_provider`,
which, where more than one provider was enabled, keeps the one that was actually
sending and turns the rest off. It does not change which provider mail goes
through.

After deploying, run `php artisan queue:restart` so queue workers load the new
code — the pipeline does not do this today.
