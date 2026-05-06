# Mailcow Calendar Sync UI Prototype

This repository contains a stronger starter implementation for a **Mailcow calendar sync management page** with a secure two-endpoint model and an **admin-only provider setup flow**.

It is designed as an **upstream-facing prototype** for the Mailcow community:

- Logged-in Mailcow users only
- Multiple calendar sync definitions per user
- Add flow asks for **two calendar email addresses**
- Google Calendar support in the provider flow
- Outlook / Microsoft 365 support in the provider flow
- Two-way and one-way sync modes
- Admin-only provider setup for Google and Microsoft
- Safer account separation between OAuth connections and sync jobs

## Recommended provider setup model

The best deployment model is:

- Mailbox users create syncs and connect their own Google/Outlook accounts
- A global Mailcow admin configures the shared server-side OAuth app credentials once
- End users never paste provider client secrets into the UI

For upstream/community adoption, this is safer than storing provider app secrets through a web form.

## What is implemented now

### UI

- A Mailcow-style page in `ui/calendar_sync.html`
- Tabbed layout for:
  - Sync links
  - External accounts
  - Provider setup (admin only)
- Session summary showing whether the current login is:
  - a mailbox user
  - a global admin
  - both mailbox-capable and admin-capable
- Admin provider setup form for Google and Microsoft with:
  - hidden client secret after save
  - enabled/disabled state
  - update/remove actions
  - provider status table
- External account section for Google and Outlook
- Sync creation form with two calendar email addresses, direction, conflict policy, and interval
- Sync table with enable, pause, and delete actions

### API

- JSON API in `api/calendar-sync.php`
- Mailcow-session authentication gate
- CSRF validation for all mutating requests
- Admin-only provider-settings API
- Ownership validation for connected external accounts
- Restriction that Mailcow endpoints can only use the authenticated mailbox
- Duplicate sync prevention using a server-side dedupe hash
- Audit logging for account and job changes
- Dashboard response that separates:
  - admin provider setup state
  - mailbox external accounts
  - mailbox sync links
- Safe initial job state:
  - `idle` when every required account is ready
  - `awaiting_account` when external OAuth is still pending
- Google and Microsoft OAuth start/callback handlers that can complete the authorization-code flow once provider credentials exist

### Database

- Schema in `sql/calendar_sync.sql`
- Separate tables for:
  - Admin provider settings
  - External OAuth accounts
  - Sync jobs
  - Event mapping
  - Audit logs

## Security model

- Mailcow login is required before the page can load data or create syncs.
- Only global admins can save, enable, disable, or remove provider credentials.
- External access is modeled as OAuth-based provider accounts, not password entry.
- Provider client secrets are encrypted at rest and never returned by the UI after save.
- Sync jobs reference provider accounts instead of storing raw provider credentials in each job.
- OAuth tokens and provider secrets are encrypted using `MC_CALSYNC_CRYPTO_KEY`.
- Jobs that depend on incomplete OAuth setup are created disabled and marked `awaiting_account`.

## What still needs to be added for production Mailcow upstreaming

This repo now provides the **UI, provider setup flow, and API contract** for the feature, but it is **not yet a full sync engine**.

Mailcow would still need:

1. Worker/queue processing for event delta sync
2. Calendar discovery against Mailcow, Google, and Microsoft APIs
3. Token refresh lifecycle management
4. Conflict-resolution execution logic
5. Background retry/recovery handling and telemetry
6. Admin review, permissions review, and final UI integration into native Mailcow navigation/templates

## Environment variables and provider setup modes

The API can use two provider setup modes:

1. Recommended Mailcow UI mode
- Global admin signs in
- Global admin opens `calendar_sync.html`
- Global admin saves Google/Microsoft client settings in the Provider setup tab
- Secrets are encrypted into the Calendar Sync database tables and hidden after save

2. Environment fallback mode
- Mailcow operator places provider credentials into environment values for `php-fpm-mailcow`
- Calendar Sync reads them as a fallback when no UI-managed provider settings exist

Always required:

- `MC_CALSYNC_CRYPTO_KEY`

The API may also need these only if Mailcow does not expose its own PDO connection:

- `MC_DB_DSN`
- `MC_DB_USER`
- `MC_DB_PASS`

Environment fallback values for Google OAuth:

- `MC_CALSYNC_GOOGLE_CLIENT_ID`
- `MC_CALSYNC_GOOGLE_CLIENT_SECRET`
- `MC_CALSYNC_GOOGLE_REDIRECT_URI` (optional, defaults to `/api/oauth/google/callback.php`)
- `MC_CALSYNC_GOOGLE_SCOPES` (optional)

Environment fallback values for Microsoft OAuth:

- `MC_CALSYNC_MICROSOFT_CLIENT_ID`
- `MC_CALSYNC_MICROSOFT_CLIENT_SECRET`
- `MC_CALSYNC_MICROSOFT_TENANT_ID` (optional, defaults to `common`)
- `MC_CALSYNC_MICROSOFT_REDIRECT_URI` (optional, defaults to `/api/oauth/microsoft/callback.php`)
- `MC_CALSYNC_MICROSOFT_SCOPES` (optional)

Two helper files are included for Mailcow operators:

- `mailcow-calendar-sync.env.example`
- `docker-compose.override.yml.example`

`MC_CALSYNC_CRYPTO_KEY` should be a strong random secret. A base64-encoded 32-byte key is a good fit.

## Install into a Mailcow test instance

Assuming Mailcow is installed at `/opt/mailcow-dockerized`:

1. Copy UI and API files:

```bash
sudo mkdir -p /opt/mailcow-dockerized/data/web/api/oauth/google
sudo mkdir -p /opt/mailcow-dockerized/data/web/api/oauth/microsoft
sudo cp ui/calendar_sync.html /opt/mailcow-dockerized/data/web/calendar_sync.html
sudo cp api/calendar-sync.php /opt/mailcow-dockerized/data/web/api/calendar-sync.php
sudo cp api/oauth/common.php /opt/mailcow-dockerized/data/web/api/oauth/common.php
sudo cp api/oauth/google/start.php /opt/mailcow-dockerized/data/web/api/oauth/google/start.php
sudo cp api/oauth/google/callback.php /opt/mailcow-dockerized/data/web/api/oauth/google/callback.php
sudo cp api/oauth/microsoft/start.php /opt/mailcow-dockerized/data/web/api/oauth/microsoft/start.php
sudo cp api/oauth/microsoft/callback.php /opt/mailcow-dockerized/data/web/api/oauth/microsoft/callback.php
```

2. Import the schema:

```bash
sudo cp sql/calendar_sync.sql /opt/mailcow-dockerized/calendar_sync.sql
cd /opt/mailcow-dockerized
sudo docker compose exec mysql-mailcow mysql -u root -p
```

Then run:

```sql
USE mailcow;
SOURCE /opt/mailcow-dockerized/calendar_sync.sql;
```

3. Add `MC_CALSYNC_CRYPTO_KEY` to Mailcow `php-fpm-mailcow` before using provider setup or OAuth:

```bash
cd /opt/mailcow-dockerized
cp docker-compose.override.yml.example docker-compose.override.yml
cp mailcow-calendar-sync.env.example mailcow-calendar-sync.env
```

Replace the example key with a real strong secret, then restart:

```bash
docker compose up -d php-fpm-mailcow nginx-mailcow
```

Leave the optional Google/Microsoft environment lines commented out unless you intentionally want environment-based provider fallback instead of the admin setup tab.

For a faster Linux-side setup, you can also run:

```bash
bash scripts/setup-mailcow-calendar-sync-crypto.sh /opt/mailcow-dockerized
```

That helper will:

- create `mailcow-calendar-sync.env` if it does not exist
- generate `MC_CALSYNC_CRYPTO_KEY` if it is still a placeholder
- create `docker-compose.override.yml` from the example if needed
- restart `php-fpm-mailcow` and `nginx-mailcow`
- verify that `php-fpm-mailcow` received the key

If you already have a custom `docker-compose.override.yml`, the script will stop safely and write a merge snippet instead of guessing how to rewrite your YAML.

4. Open the page while signed in to Mailcow:

- `https://<your-mailcow-host>/calendar_sync.html`

5. While signed in as a global Mailcow admin, open the `Provider setup` tab and save Google and/or Microsoft credentials.

6. Configure your provider OAuth apps with these callback URLs:

- Google: `https://<your-mailcow-host>/api/oauth/google/callback.php`
- Microsoft: `https://<your-mailcow-host>/api/oauth/microsoft/callback.php`

7. While signed in as a mailbox user, use:

- `External accounts` to save a Google/Outlook account and finish OAuth
- `Sync links` to create calendar pairings

## Google OAuth test setup

For a focused Google test run, use the admin Provider setup tab together with the Google Cloud checklist in:

- `GOOGLE_OAUTH_SETUP.md`

Recommended Google test values:

- Audience: `External`
- Publishing status: `Testing`
- Test users: every Google account that will authorize during testing
- Redirect URI: `https://<your-mailcow-host>/api/oauth/google/callback.php`
- Scopes: `openid email https://www.googleapis.com/auth/calendar`

The Provider setup tab in `calendar_sync.html` now shows the same Google checklist inline so the admin can copy the redirect URI and scopes directly from the Mailcow UI.

## Mailcow admin setup

Recommended deployment for the community version:

1. Keep `MC_CALSYNC_CRYPTO_KEY` in Mailcow server environment configuration
2. Use the Calendar Sync admin UI for Google/Microsoft provider credentials
3. Reserve environment fallback values for bootstrap, automation, or emergency override cases
4. Do not ask mailbox users for provider client secrets

## Suggested upstream framing

If you hand this to the Mailcow community, the most accurate description is:

> A secure UI/API scaffold for per-user Mailcow calendar sync management with admin-controlled Google and Outlook provider setup, ready for sync-worker and calendar-discovery integration.

That framing is honest about what is complete today and what still belongs in a full Mailcow release.
