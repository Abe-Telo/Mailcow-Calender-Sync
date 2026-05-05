# Mailcow Calendar Sync (Two-Way)

This repository includes a secure starter implementation for a Mailcow UI module that allows users to create multiple calendar sync links between:

- Mailcow ↔ Mailcow
- Mailcow ↔ Google Calendar
- Mailcow ↔ Outlook Calendar

It now supports sync direction modes:
- Two-way sync (A ↔ B)
- One-way sync (A → B)
- One-way sync (B → A)

## Ubuntu 24.04.4 LTS install/test instructions

Assuming your Mailcow is installed at `/opt/mailcow-dockerized`:

1. Copy UI + API files:
   - `ui/calendar_sync.html` → `/opt/mailcow-dockerized/data/web/calendar_sync.html`
   - `api/calendar-sync.php` → `/opt/mailcow-dockerized/data/web/api/calendar-sync.php`

   ```bash
   sudo mkdir -p /opt/mailcow-dockerized/data/web/api
   sudo cp ui/calendar_sync.html /opt/mailcow-dockerized/data/web/calendar_sync.html
   sudo cp api/calendar-sync.php /opt/mailcow-dockerized/data/web/api/calendar-sync.php
   ```

2. Import SQL table:
   ```bash
   sudo cp sql/calendar_sync.sql /opt/mailcow-dockerized/calendar_sync.sql
   cd /opt/mailcow-dockerized
   sudo docker compose exec mysql-mailcow mysql -u root -p
   ```

   In mysql shell:
   ```sql
   USE mailcow;
   SOURCE /opt/mailcow-dockerized/calendar_sync.sql;
   ```

3. Open in browser (while logged into Mailcow):
   - `https://<your-mailcow-host>/calendar_sync.html`

## What is implemented

1. Password/Mailcow auth represented in UI
2. Multiple calendar sync definitions
3. Add button asks for 2 calendar emails
4. Logged-in users only
5. Google Calendar support in provider list
6. Outlook Calendar support in provider list
7. Sync direction selection (two-way, A→B, B→A)
8. Google connect popup button shown when Provider A or B is Google
9. CSRF token header validation for POST (`X-CSRF-Token`)

## Google popup behavior

When Provider A or Provider B is set to Google Calendar, a **Connect Google** button appears. Clicking it opens:

- `/api/oauth/google/start.php?side=A`
- `/api/oauth/google/start.php?side=B`

You need to implement that OAuth start/callback flow in Mailcow to complete real Google token linking.

## CSRF token flow (required integration)

`calendar_sync.html` expects a server-populated token in `window.CALENDAR_SYNC_CSRF_TOKEN`. The page copies that value into a hidden field and a `<meta name="csrf-token">` entry, then submits it in the `X-CSRF-Token` header for `POST /api/calendar-sync.php`.

On the API side:
- A per-session token is stored in `$_SESSION['csrf_token']`.
- POST requests are rejected with `403` JSON (`{"error":"CSRF validation failed"}`) unless `X-CSRF-Token` matches the session token.

### Rotate token on login/session renewal

In your Mailcow login/session renewal path, rotate both the PHP session ID and CSRF token, for example:

```php
session_regenerate_id(true);
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
```

Also inject the fresh token into the rendered calendar sync page before frontend JS runs:

```html
<script>window.CALENDAR_SYNC_CSRF_TOKEN = "<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>";</script>
```

This ensures stale tokens from prior sessions cannot be reused.

## Security controls included

- Session-auth requirement (`401` for unauthenticated users)
- CSRF validation on POST (`403` on mismatch/missing header)
- Provider and sync-direction allowlists
- Strict input validation for `POST /api/calendar-sync.php` with `422` field-level errors:
  - Required string fields: `name`, `email_a`, `email_b`, `provider_a`, `provider_b`, `sync_direction`, `mailcow_secret`
  - Trimmed non-empty values
  - Length constraints: `name` ≤ 120, `email_a`/`email_b` ≤ 255, `provider_a`/`provider_b` ≤ 32, `sync_direction` ≤ 16, `mailcow_secret` ≤ 255
  - Email format checks via `filter_var(..., FILTER_VALIDATE_EMAIL)`
  - Explicit rejection of empty `mailcow_secret` before hashing
- Password hashing using Argon2id (never plaintext storage)
- Prepared SQL statements for inserts/selects

## Production hardening recommended before upstreaming to Mailcow

- Replace secret hash approach with encrypted token vault + OAuth token lifecycle for Google/Outlook
- Add strict server-side email validation and ownership checks
- Add per-user rate limiting and audit logging
- Add background worker and conflict-resolution policy for actual sync execution
