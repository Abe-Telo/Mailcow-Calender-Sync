<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'common.php';

calendarSyncBootstrapSession();

$owner = calendarSyncRequireMailboxUser();
$state = calendarSyncGetRequiredString('state');
$attempt = calendarSyncConsumeOauthAttempt('google', $state);
if ($attempt === null) {
  calendarSyncRenderHtmlPage(400, 'OAuth session expired', 'The Google OAuth flow could not be matched to a pending connection request. Start the connection again from Calendar Sync.');
}

if (!isset($attempt['owner'], $attempt['account_id']) || $attempt['owner'] !== $owner) {
  calendarSyncRenderHtmlPage(403, 'OAuth session mismatch', 'This Google OAuth callback does not match the signed-in Mailcow mailbox.');
}

$pdo = calendarSyncResolveDatabaseConnection();
$accountId = (int) $attempt['account_id'];
$account = calendarSyncFetchOwnedAccount($pdo, $owner, $accountId, 'google');
if ($account === null) {
  calendarSyncRenderHtmlPage(404, 'Account not found', 'The selected Google account could not be found anymore.');
}

if (isset($_GET['error']) && trim((string) $_GET['error']) !== '') {
  $providerError = trim((string) $_GET['error']);
  calendarSyncMarkAccountError($pdo, $owner, $accountId, 'Google OAuth error: ' . $providerError);
  calendarSyncAudit($pdo, $owner, 'account_oauth_callback', 'account', $accountId, 'failure', ['provider' => 'google', 'error' => $providerError]);
  calendarSyncRedirect(calendarSyncBuildReturnUrl([
    'oauth_status' => 'error',
    'provider' => 'google',
    'message' => 'Google OAuth was cancelled or rejected.',
  ]));
}

$code = $_GET['code'] ?? '';
if (!is_string($code) || trim($code) === '') {
  calendarSyncRenderHtmlPage(422, 'Missing authorization code', 'Google did not return an authorization code.');
}

$providerConfig = calendarSyncResolveProviderConfiguration($pdo, 'google');
if (!$providerConfig['configured']) {
  calendarSyncRenderHtmlPage(503, 'Google OAuth not configured', 'Google client settings are missing on the Mailcow server.');
}

$tokenResponse = calendarSyncHttpPostForm('https://oauth2.googleapis.com/token', [
  'client_id' => $providerConfig['client_id'],
  'client_secret' => $providerConfig['client_secret'],
  'code' => trim($code),
  'grant_type' => 'authorization_code',
  'redirect_uri' => $providerConfig['redirect_uri'],
]);

if ($tokenResponse['error'] !== null || $tokenResponse['status'] >= 400 || !is_array($tokenResponse['json']) || empty($tokenResponse['json']['access_token'])) {
  $failureMessage = 'Google token exchange failed.';
  if (is_array($tokenResponse['json']) && isset($tokenResponse['json']['error_description']) && is_string($tokenResponse['json']['error_description'])) {
    $failureMessage = 'Google token exchange failed: ' . $tokenResponse['json']['error_description'];
  } elseif (is_string($tokenResponse['error']) && $tokenResponse['error'] !== '') {
    $failureMessage = 'Google token exchange failed: ' . $tokenResponse['error'];
  }

  calendarSyncMarkAccountError($pdo, $owner, $accountId, $failureMessage);
  calendarSyncAudit($pdo, $owner, 'account_oauth_callback', 'account', $accountId, 'failure', ['provider' => 'google', 'http_status' => $tokenResponse['status']]);
  calendarSyncRedirect(calendarSyncBuildReturnUrl([
    'oauth_status' => 'error',
    'provider' => 'google',
    'message' => 'Google token exchange failed.',
  ]));
}

$payload = $tokenResponse['json'];
calendarSyncUpdateAccountTokens(
  $pdo,
  $owner,
  $accountId,
  (string) $payload['access_token'],
  isset($payload['refresh_token']) && is_string($payload['refresh_token']) ? $payload['refresh_token'] : null,
  isset($payload['scope']) && is_string($payload['scope']) ? $payload['scope'] : null,
  isset($payload['expires_in']) ? (int) $payload['expires_in'] : null,
  isset($account['encrypted_refresh_token']) && is_string($account['encrypted_refresh_token']) ? $account['encrypted_refresh_token'] : null
);
calendarSyncResumeReadyJobs($pdo, $owner);
calendarSyncAudit($pdo, $owner, 'account_oauth_callback', 'account', $accountId, 'success', ['provider' => 'google']);

calendarSyncRedirect(calendarSyncBuildReturnUrl([
  'oauth_status' => 'connected',
  'provider' => 'google',
]));
