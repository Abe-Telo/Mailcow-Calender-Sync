# Mailcow Calendar Sync (Built-in UI Prototype)

This repository contains a starter implementation for a Mailcow user-level **Calendar Sync** page.

## Implemented in this prototype

- User-scoped Calendar Sync UI page (`ui/calendar_sync.html`)
- API endpoint for user-scoped resources (`api/calendar-sync.php`)
- SQL schema for:
  - Connected external accounts (`calendar_sync_accounts`)
  - Sync jobs (`calendar_sync_jobs`)
  - Event mapping (`calendar_sync_event_map`)
  - Audit logs (`calendar_sync_audit_log`)
- CSRF validation for mutating API methods
- Ownership checks for external account usage when creating sync jobs
- Sync modes:
  - `two_way`
  - `mailcow_to_external`
  - `external_to_mailcow`
- Conflict policy options:
  - `newest_wins`
  - `prefer_mailcow`
  - `prefer_external`
  - `manual`

## Notes

- OAuth integrations are currently **stubbed placeholders** in `POST /api/calendar-sync.php?path=accounts`.
- Real token exchange, encryption-at-rest, refresh lifecycle, and provider delta sync should be implemented in dedicated OAuth + worker components.
- This API assumes Mailcow session authentication and environment variables:
  - `MC_DB_DSN`
  - `MC_DB_USER`
  - `MC_DB_PASS`

## Install

1. Copy files into Mailcow web root:

```bash
sudo mkdir -p /opt/mailcow-dockerized/data/web/api
sudo cp ui/calendar_sync.html /opt/mailcow-dockerized/data/web/calendar_sync.html
sudo cp api/calendar-sync.php /opt/mailcow-dockerized/data/web/api/calendar-sync.php
```

2. Import schema:

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

3. Open page while logged in:

- `https://<your-mailcow-host>/calendar_sync.html`

## Troubleshooting

If UI shows "Failed to connect account" or "Failed to create job":

1. Open browser dev tools and inspect the API response body and HTTP status.
2. Confirm your Mailcow session is valid (`401` means not authenticated).
3. Confirm CSRF token is present and valid (`403` means token mismatch).
4. Confirm DB env vars are configured in web container:
   - `MC_DB_DSN`
   - `MC_DB_USER`
   - `MC_DB_PASS`
5. Confirm schema has been imported and includes all `calendar_sync_*` tables.
