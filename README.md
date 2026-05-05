# Mailcow Calendar Sync (Two-Way)

This repository now includes a **secure starter implementation** for a Mailcow UI module that allows users to create **multiple two-way calendar sync links** between:

- Mailcow ↔ Mailcow
- Mailcow ↔ Google Calendar
- Mailcow ↔ Outlook Calendar

## What is implemented

1. **Password/Mailcow auth represented in UI**
   - Input field for Mailcow/app password in the Add Sync form.
2. **Multiple calendar sync definitions**
   - Each user can create many sync link entries.
3. **Add button asks for 2 calendar emails**
   - Email A + Email B required.
4. **Logged-in users only**
   - API checks active Mailcow user session.
5. **Google Calendar support in provider list**
6. **Outlook Calendar support in provider list**

## Files

- `ui/calendar_sync.html` — UI page with form + list.
- `api/calendar-sync.php` — Authenticated API for listing/creating sync entries.
- `sql/calendar_sync.sql` — Database schema.

## Security controls included

- Session-auth requirement (`401` for unauthenticated users).
- Provider allowlist (`mailcow`, `google`, `outlook`).
- Password hashing using Argon2id (never plaintext storage).
- Prepared SQL statements for inserts/selects.

## Production hardening recommended before upstreaming to Mailcow

- Replace secret hash approach with encrypted token vault + OAuth token lifecycle for Google/Outlook.
- Add CSRF tokens on POST operations.
- Add server-side email validation and domain policy controls.
- Add per-user rate limiting and audit logging.
- Add RBAC checks for admin-only operations where needed.
- Add background worker + webhook/ICS change detection for actual sync execution.
- Add conflict resolution policy (last-write-wins vs vector clocks).
- Add encryption-at-rest for provider refresh tokens.

## Goal for Mailcow community

This is a clean foundation to submit as a feature proposal/starting patch for inclusion in a future Mailcow update.
