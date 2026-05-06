<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'common.php';

calendarSyncBootstrapSession();

$owner = calendarSyncRequireMailboxUser();
$pdo = calendarSyncResolveDatabaseConnection();
$accountId = calendarSyncGetRequiredInt('account_id');
$linkState = calendarSyncGetRequiredString('state');

$account = calendarSyncFetchOwnedAccount($pdo, $owner, $accountId, 'google');
if ($account === null) {
  calendarSyncRenderHtmlPage(404, 'Account not found', 'The selected Google account does not belong to this Mailcow mailbox.');
}

if (!hash_equals((string) $account['oauth_state'], $linkState)) {
  calendarSyncRenderHtmlPage(403, 'Invalid reconnect link', 'The Google OAuth link is stale or invalid. Refresh the Calendar Sync page and try again.');
}

$providerConfig = calendarSyncResolveProviderConfiguration($pdo, 'google');
if (!$providerConfig['configured']) {
  calendarSyncRenderHtmlPage(
    503,
    'Google OAuth not configured',
    'Set Google OAuth client settings in Mailcow before connecting this account.',
    [
      'The Mailcow administrator must save Google client credentials in Calendar Sync provider setup, or provide environment fallback values.',
      'Expected redirect URI: ' . $providerConfig['redirect_uri'],
    ]
  );
}

if (!$providerConfig['available']) {
  calendarSyncRenderHtmlPage(503, 'Google OAuth disabled', 'Google provider setup exists, but it is currently disabled by the Mailcow administrator.');
}

$oauthState = bin2hex(random_bytes(32));
calendarSyncStoreOauthAttempt('google', $oauthState, $owner, $accountId);

$query = http_build_query([
  'client_id' => $providerConfig['client_id'],
  'redirect_uri' => $providerConfig['redirect_uri'],
  'response_type' => 'code',
  'scope' => $providerConfig['scopes'],
  'state' => $oauthState,
  'access_type' => 'offline',
  'prompt' => 'consent',
  'include_granted_scopes' => 'true',
  'login_hint' => (string) $account['provider_account_email'],
]);

calendarSyncRedirect('https://accounts.google.com/o/oauth2/v2/auth?' . $query);
