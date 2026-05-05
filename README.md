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

## Google popup behavior

When Provider A or Provider B is set to Google Calendar, a **Connect Google** button appears. Clicking it opens:

- `/api/oauth/google/start.php?side=A`
- `/api/oauth/google/start.php?side=B`

You need to implement that OAuth start/callback flow in Mailcow to complete real Google token linking.

## Security controls included

- Session-auth requirement (`401` for unauthenticated users)
- Provider and sync-direction allowlists
- Password hashing using Argon2id (never plaintext storage)
- Prepared SQL statements for inserts/selects

## Production hardening recommended before upstreaming to Mailcow

- Replace secret hash approach with encrypted token vault + OAuth token lifecycle for Google/Outlook
- Add CSRF tokens on POST operations
- Add strict server-side email validation and ownership checks
- Add per-user rate limiting and audit logging
- Add background worker and conflict-resolution policy for actual sync execution
