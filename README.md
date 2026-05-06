# Mailcow Calendar Sync UI Prototype

This repository contains a stronger starter implementation for a **Mailcow calendar sync management page** with a secure two-endpoint model.

It is designed as an **upstream-facing prototype** for the Mailcow community:

- Logged-in Mailcow users only
- Multiple calendar sync definitions per user
- Add flow asks for **two calendar email addresses**
- Google Calendar support in the provider flow
- Outlook / Microsoft 365 support in the provider flow
- Two-way and one-way sync modes
- Safer account separation between OAuth connections and sync jobs

## What is implemented now

### UI

- A Mailcow-oriented page in `ui/calendar_sync.html`
- Session summary showing the authenticated Mailcow mailbox
- External account section for Google and Outlook
- Sync creation form with:
  - Sync name
  - Calendar A email
  - Calendar B email
  - Provider selection for both sides
  - Direction mode
  - Conflict policy
  - Sync interval
- Sync table with enable, pause, and delete actions

### API

- JSON API in `api/calendar-sync.php`
- Mailcow-session authentication gate
- CSRF validation for all mutating requests
- Ownership validation for connected external accounts
- Restriction that Mailcow endpoints can only use the authenticated mailbox
- Duplicate sync prevention using a server-side dedupe hash
- Audit logging for account and job changes
- Safe initial job state:
  - `idle` when every required account is ready
  - `awaiting_account` when external OAuth is still pending

### Database

- Schema in `sql/calendar_sync.sql`
- Separate tables for:
  - External OAuth accounts
  - Sync jobs
  - Event mapping
  - Audit logs

## Security model

- Mailcow login is required before the page can load data or create syncs.
- External access is modeled as OAuth-based provider accounts, not password entry.
- Sync jobs reference provider accounts instead of storing raw provider credentials in each job.
- If OAuth tokens are posted into the API by a real callback flow later, the API is prepared to encrypt them using `MC_CALSYNC_CRYPTO_KEY`.
- Jobs that depend on incomplete OAuth setup are created disabled and marked `awaiting_account`.

## What still needs to be added for production Mailcow upstreaming

This repo now provides the **UI and API contract** for the feature, but it is **not yet a full sync engine**.

Mailcow would still need:

1. Real Google OAuth start/callback handlers
2. Real Microsoft OAuth start/callback handlers
3. Worker/queue processing for event delta sync
4. Calendar discovery against Mailcow, Google, and Microsoft APIs
5. Token refresh lifecycle management
6. Conflict-resolution execution logic
7. Admin review, permissions review, and final UI integration into native Mailcow navigation/templates

## Environment variables

The API expects:

- `MC_DB_DSN`
- `MC_DB_USER`
- `MC_DB_PASS`

For encrypted provider-token storage in a future OAuth callback:

- `MC_CALSYNC_CRYPTO_KEY`

`MC_CALSYNC_CRYPTO_KEY` should be a strong random secret. A base64-encoded 32-byte key is a good fit.

## Install into a Mailcow test instance

Assuming Mailcow is installed at `/opt/mailcow-dockerized`:

1. Copy UI and API files:

```bash
sudo mkdir -p /opt/mailcow-dockerized/data/web/api
sudo cp ui/calendar_sync.html /opt/mailcow-dockerized/data/web/calendar_sync.html
sudo cp api/calendar-sync.php /opt/mailcow-dockerized/data/web/api/calendar-sync.php
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

3. Open the page while signed in to Mailcow:

- `https://<your-mailcow-host>/calendar_sync.html`

## Suggested upstream framing

If you hand this to the Mailcow community, the most accurate description is:

> A secure UI/API scaffold for per-user Mailcow calendar sync management with Google and Outlook provider support, ready for OAuth callback and worker integration.

That framing is honest about what is complete today and what still belongs in a full Mailcow release.
