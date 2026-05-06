# Google OAuth Setup for Mailcow Calendar Sync

Use this checklist when testing Google Calendar OAuth with the Mailcow Calendar Sync prototype.

## Official references

- Manage OAuth Clients: https://support.google.com/cloud/answer/6158849?hl=en
- Google OAuth web server flow: https://developers.google.com/identity/protocols/oauth2/web-server
- Google Calendar API quickstart: https://developers.google.com/calendar/api/quickstart/js
- Google Auth Platform audience and test users: https://support.google.com/cloud/answer/15549945?hl=en
- Unverified apps and scope warnings: https://support.google.com/cloud/answer/7454865?hl=en

## What this build expects

- OAuth client type: `Web application`
- Redirect URI: `https://<your-mailcow-host>/api/oauth/google/callback.php`
- Scopes: `openid email https://www.googleapis.com/auth/calendar`
- Audience for testing: `External`
- Publishing status for testing: `Testing`

If you are testing on `mail.telocall.com`, the exact redirect URI is:

- `https://mail.telocall.com/api/oauth/google/callback.php`

## Google Cloud steps

1. Create or select the Google Cloud project you want to use.
2. Enable the `Google Calendar API`.
3. Open `Google Auth Platform`.
4. Complete Branding with:
   - App name: `Mailcow Calendar Sync`
   - User support email: an email you monitor
   - Developer/contact email: your admin email
5. Open Audience and choose:
   - User type: `External`
   - Publishing status: `Testing`
6. Add every Google account you will test with as a `Test user`.
7. If Google asks for scopes in Data Access, add:
   - `openid`
   - `email`
   - `https://www.googleapis.com/auth/calendar`
8. Open Clients and create a new OAuth client:
   - Application type: `Web application`
   - Name: `Mailcow Calendar Sync Web`
9. Add the authorized redirect URI exactly:
   - `https://<your-mailcow-host>/api/oauth/google/callback.php`
10. Authorized JavaScript origins are not required for this server-side flow.
    If the Google console forces one, use:
   - `https://<your-mailcow-host>`
11. Create the client and immediately copy:
   - Client ID
   - Client secret

## Mailcow admin steps

1. Make sure `MC_CALSYNC_CRYPTO_KEY` is configured for `php-fpm-mailcow`.
2. Sign in to Mailcow as a global admin.
3. Open `calendar_sync.html`.
4. Open the `Provider setup` tab.
5. Choose `Google Calendar`.
6. Paste:
   - Client ID
   - Client secret
7. Confirm:
   - Redirect URI matches your Mailcow host callback path
   - Scopes are `openid email https://www.googleapis.com/auth/calendar`
   - Enabled is checked
8. Save the provider setup.

Expected result:

- The secret is hidden after save
- Google shows as configured/enabled

## Mailbox user test

1. Sign in as a Mailcow mailbox user.
2. Open `calendar_sync.html`.
3. Go to `External accounts`.
4. Add a Google external account.
5. Click `Continue OAuth`.
6. Sign in with a Google account that is listed as a Test user.
7. Approve the consent screen.

Expected result:

- Mailcow returns to `calendar_sync.html`
- A success notice is shown
- The external account status becomes `active`

## Common failures

- `redirect_uri_mismatch`
  The redirect URI in Google does not exactly match the callback URL Mailcow is using.

- `access blocked` or app not allowed
  The Google account is not listed as a Test user, or the app audience/publishing state is wrong.

- Provider unavailable in Mailcow
  The admin provider setup was not saved, is disabled, or the latest Calendar Sync files are not deployed.

- OAuth callback returns an internal server error
  Check `php-fpm-mailcow` logs and confirm the latest files under `data/web/api/oauth/` are deployed.
