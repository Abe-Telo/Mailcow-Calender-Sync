<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'common.php';

calendarSyncBootstrapSession();

$owner = calendarSyncRequireMailboxUser();
$state = calendarSyncGetRequiredString('state');
$attempt = calendarSyncConsumeOauthAttempt('microsoft', $state);
if ($attempt === null) {
  calendarSyncRenderHtmlPage(400, 'OAuth session expired', 'The Microsoft OAuth flow could not be matched to a pending connection request. Start the connection again from Calendar Sync.');
}

if (!isset($attempt['owner'], $attempt['account_id']) || $attempt['owner'] !== $owner) {
  calendarSyncRenderHtmlPage(403, 'OAuth session mismatch', 'This Microsoft OAuth callback does not match the signed-in Mailcow mailbox.');
}

$pdo = calendarSyncResolveDatabaseConnection();
$accountId = (int) $attempt['account_id'];
$account = calendarSyncFetchOwnedAccount($pdo, $owner, $accountId, 'microsoft');
if ($account === null) {
  calendarSyncRenderHtmlPage(404, 'Account not found', 'The selected Outlook / Microsoft 365 account could not be found anymore.');
}

if (isset($_GET['error']) && trim((string) $_GET['error']) !== '') {
  $providerError = trim((string) $_GET['error']);
  calendarSyncMarkAccountError($pdo, $owner, $accountId, 'Microsoft OAuth error: ' . $providerError);
  calendarSyncAudit($pdo, $owner, 'account_oauth_callback', 'account', $accountId, 'failure', ['provider' => 'microsoft', 'error' => $providerError]);
  calendarSyncRedirect(calendarSyncBuildReturnUrl([
    'oauth_status' => 'error',
    'provider' => 'microsoft',
    'message' => 'Microsoft OAuth was cancelled or rejected.',
  ]));
}

$code = $_GET['code'] ?? '';
if (!is_string($code) || trim($code) === '') {
  calendarSyncRenderHtmlPage(422, 'Missing authorization code', 'Microsoft did not return an authorization code.');
}

$providerConfig = calendarSyncResolveProviderConfiguration($pdo, 'microsoft');
if (!$providerConfig['configured']) {
  calendarSyncRenderHtmlPage(503, 'Microsoft OAuth not configured', 'Microsoft client settings are missing on the Mailcow server.');
}

$tokenResponse = calendarSyncHttpPostForm('https://login.microsoftonline.com/' . rawurlencode((string) $providerConfig['tenant_id']) . '/oauth2/v2.0/token', [
  'client_id' => $providerConfig['client_id'],
  'client_secret' => $providerConfig['client_secret'],
  'code' => trim($code),
  'grant_type' => 'authorization_code',
  'redirect_uri' => $providerConfig['redirect_uri'],
]);

if ($tokenResponse['error'] !== null || $tokenResponse['status'] >= 400 || !is_array($tokenResponse['json']) || empty($tokenResponse['json']['access_token'])) {
  $failureMessage = 'Microsoft token exchange failed.';
  if (is_array($tokenResponse['json']) && isset($tokenResponse['json']['error_description']) && is_string($tokenResponse['json']['error_description'])) {
    $failureMessage = 'Microsoft token exchange failed: ' . $tokenResponse['json']['error_description'];
  } elseif (is_string($tokenResponse['error']) && $tokenResponse['error'] !== '') {
    $failureMessage = 'Microsoft token exchange failed: ' . $tokenResponse['error'];
  }

  calendarSyncMarkAccountError($pdo, $owner, $accountId, $failureMessage);
  calendarSyncAudit($pdo, $owner, 'account_oauth_callback', 'account', $accountId, 'failure', ['provider' => 'microsoft', 'http_status' => $tokenResponse['status']]);
  calendarSyncRedirect(calendarSyncBuildReturnUrl([
    'oauth_status' => 'error',
    'provider' => 'microsoft',
    'message' => 'Microsoft token exchange failed.',
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
calendarSyncAudit($pdo, $owner, 'account_oauth_callback', 'account', $accountId, 'success', ['provider' => 'microsoft']);

calendarSyncRedirect(calendarSyncBuildReturnUrl([
  'oauth_status' => 'connected',
  'provider' => 'microsoft',
]));
