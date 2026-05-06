<?php
declare(strict_types=1);

function detectMailcowBootstrapPath(): ?string {
  $candidates = [
    __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'prerequisites.inc.php',
  ];

  $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
  if (is_string($documentRoot) && $documentRoot !== '') {
    $candidates[] = rtrim($documentRoot, '/\\') . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'prerequisites.inc.php';
  }

  foreach ($candidates as $candidate) {
    $resolved = realpath($candidate);
    if (is_string($resolved) && is_file($resolved)) {
      return $resolved;
    }
  }

  return null;
}

function isHttpsRequest(): bool {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    return true;
  }

  $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
  return is_string($forwardedProto) && strtolower($forwardedProto) === 'https';
}

function bootstrapStandaloneSession(): void {
  ini_set('session.use_strict_mode', '1');

  if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
      'httponly' => true,
      'secure' => isHttpsRequest(),
      'samesite' => 'Lax',
    ]);
  }

  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
}

$mailcowBootstrapPath = detectMailcowBootstrapPath();
$mailcowBootstrapped = false;

if ($mailcowBootstrapPath !== null) {
  require_once $mailcowBootstrapPath;
  $mailcowBootstrapped = true;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
  bootstrapStandaloneSession();
}

header('Content-Type: application/json; charset=utf-8');

function jsonResponse(int $statusCode, array $payload): void {
  http_response_code($statusCode);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload);
  exit;
}

function trimToLength(string $value, int $maxLength): string {
  if (function_exists('mb_substr')) {
    return mb_substr($value, 0, $maxLength);
  }

  return substr($value, 0, $maxLength);
}

function denyCsrf(): void {
  jsonResponse(403, ['error' => 'CSRF validation failed']);
}

function internalErrorResponse(string $message, Throwable $exception, int $statusCode = 500): void {
  error_log(sprintf('%s: %s in %s:%d', $message, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
  jsonResponse($statusCode, ['error' => 'Internal server error']);
}

function nestedSessionValue(array $path): mixed {
  $value = $_SESSION;
  foreach ($path as $segment) {
    if (!is_array($value) || !array_key_exists($segment, $value)) {
      return null;
    }
    $value = $value[$segment];
  }
  return $value;
}

function resolveSessionUsername(): ?string {
  $candidates = [
    $_SESSION['mailcow_cc_username'] ?? null,
    $_SESSION['mailcow_user'] ?? null,
    $_SESSION['login_user'] ?? null,
  ];

  foreach ($candidates as $candidate) {
    if (!is_string($candidate)) {
      continue;
    }

    $trimmed = trim($candidate);
    if ($trimmed !== '') {
      return trimToLength($trimmed, 255);
    }
  }

  return null;
}

function resolveMailcowRole(): ?string {
  $candidates = [
    $_SESSION['mailcow_cc_role'] ?? null,
    $_SESSION['role'] ?? null,
  ];

  foreach ($candidates as $candidate) {
    if (!is_string($candidate)) {
      continue;
    }

    $trimmed = strtolower(trim($candidate));
    if ($trimmed !== '') {
      return trimToLength($trimmed, 64);
    }
  }

  return null;
}

function resolveAuthenticatedMailbox(): ?string {
  $mailcowUsername = $_SESSION['mailcow_cc_username'] ?? null;
  $mailcowRole = $_SESSION['mailcow_cc_role'] ?? null;
  if (is_string($mailcowUsername) && $mailcowUsername !== '' && is_string($mailcowRole) && $mailcowRole !== '') {
    $candidate = strtolower(trim($mailcowUsername));
    if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
      return trimToLength($candidate, 255);
    }
  }

  $candidates = [
    ['mailcow_user'],
    ['mailcow_cc_username'],
    ['mailcow_cc_mailbox'],
    ['mailcow', 'mailbox'],
    ['mailcow', 'username'],
    ['login_user'],
  ];

  foreach ($candidates as $path) {
    $value = nestedSessionValue($path);
    if (!is_string($value)) {
      continue;
    }

    $candidate = strtolower(trim($value));
    if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
      return trimToLength($candidate, 255);
    }
  }

  return null;
}

function resolveSessionContext(): array {
  $username = resolveSessionUsername();
  $role = resolveMailcowRole();
  $mailbox = resolveAuthenticatedMailbox();

  return [
    'username' => $username,
    'role' => $role,
    'mailbox' => $mailbox,
    'authenticated' => $username !== null || $mailbox !== null || $role !== null,
    'is_admin' => $role === 'admin',
    'can_manage_syncs' => $mailbox !== null,
  ];
}

function authDebugEnabled(): bool {
  return isset($_GET['debug']) && (string) $_GET['debug'] === '1';
}

function buildAuthDebugPayload(?string $bootstrapPath, bool $bootstrapped, array $session): array {
  $sessionValues = [];
  $keysToInspect = [
    'mailcow_cc_username',
    'mailcow_cc_role',
    'mailcow_cc_mailbox',
    'mailcow_user',
    'login_user',
  ];

  foreach ($keysToInspect as $key) {
    if (!array_key_exists($key, $_SESSION)) {
      continue;
    }

    $value = $_SESSION[$key];
    $sessionValues[$key] = is_scalar($value) || $value === null ? $value : gettype($value);
  }

  return [
    'bootstrap_path' => $bootstrapPath ?? 'not-found',
    'mailcow_bootstrapped' => $bootstrapped,
    'session_name' => session_name(),
    'session_status' => session_status(),
    'cookie_names_present' => array_keys($_COOKIE),
    'session_keys_present' => array_keys($_SESSION),
    'session_values' => $sessionValues,
    'resolved_role' => $session['role'],
    'resolved_username' => $session['username'],
    'resolved_mailbox' => $session['mailbox'],
  ];
}

function actorIdentity(array $session): string {
  return $session['mailbox']
    ?? $session['username']
    ?? $session['role']
    ?? 'unknown';
}

function auditOwnerMailbox(array $session): string {
  return $session['mailbox'] ?? '__admin__';
}

function requireAdmin(array $session): void {
  if (!$session['is_admin']) {
    jsonResponse(403, ['error' => 'Administrator access required']);
  }
}

function requireMailboxAccess(array $session): string {
  if (!is_string($session['mailbox']) || $session['mailbox'] === '') {
    jsonResponse(403, ['error' => 'Mailbox user access required']);
  }

  return $session['mailbox'];
}

function normalizeEmail(string $value, string $fieldName): string {
  $normalized = strtolower(trim($value));
  if ($normalized === '' || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(422, ['error' => "Invalid {$fieldName}"]);
  }

  return trimToLength($normalized, 255);
}

function normalizeProvider(string $value, bool $allowMailcow = true): string {
  $provider = strtolower(trim($value));
  if ($provider === 'outlook' || $provider === 'microsoft365') {
    $provider = 'microsoft';
  }

  $allowed = $allowMailcow
    ? ['mailcow', 'google', 'microsoft']
    : ['google', 'microsoft'];

  if (!in_array($provider, $allowed, true)) {
    jsonResponse(422, ['error' => 'Unsupported provider']);
  }

  return $provider;
}

function providerLabel(string $provider): string {
  return match ($provider) {
    'mailcow' => 'Mailcow',
    'google' => 'Google Calendar',
    'microsoft' => 'Outlook / Microsoft 365',
    default => ucfirst($provider),
  };
}

function normalizeMode(string $value): string {
  $mode = strtolower(trim($value));
  $allowed = ['two_way', 'a_to_b', 'b_to_a'];
  if (!in_array($mode, $allowed, true)) {
    jsonResponse(422, ['error' => 'Unsupported sync mode']);
  }

  return $mode;
}

function normalizeConflictPolicy(string $value): string {
  $policy = strtolower(trim($value));
  $allowed = ['newest_wins', 'prefer_mailcow', 'prefer_external', 'manual'];
  if (!in_array($policy, $allowed, true)) {
    jsonResponse(422, ['error' => 'Unsupported conflict policy']);
  }

  return $policy;
}

function normalizeOptionalString(mixed $value, int $maxLength): ?string {
  if (!is_scalar($value)) {
    return null;
  }

  $trimmed = trim((string) $value);
  if ($trimmed === '') {
    return null;
  }

  return trimToLength($trimmed, $maxLength);
}

function normalizeBoolean(mixed $value, string $fieldName): bool {
  if (!is_bool($value)) {
    jsonResponse(422, ['error' => "{$fieldName} must be a boolean"]);
  }

  return $value;
}

function providerStatusLabel(string $provider, array $setting): string {
  if (($setting['source'] ?? 'missing') === 'missing') {
    return 'Not configured';
  }

  if (!$setting['secret_configured']) {
    return 'Secret missing';
  }

  if (!$setting['enabled']) {
    return 'Disabled';
  }

  return $provider === 'google' || $provider === 'microsoft'
    ? 'Configured'
    : 'Available';
}

function syncModeLabel(string $mode): string {
  return match ($mode) {
    'two_way' => 'Two-way',
    'a_to_b' => 'Calendar A -> Calendar B',
    'b_to_a' => 'Calendar B -> Calendar A',
    default => $mode,
  };
}

function jobStatusLabel(string $status): string {
  return match ($status) {
    'idle' => 'Ready',
    'running' => 'Running',
    'error' => 'Needs attention',
    'paused' => 'Paused',
    'awaiting_account' => 'Waiting for OAuth',
    default => ucfirst(str_replace('_', ' ', $status)),
  };
}

function readJsonBody(): array {
  $payload = json_decode((string) file_get_contents('php://input'), true);
  if (!is_array($payload)) {
    jsonResponse(400, ['error' => 'Invalid JSON body']);
  }

  return $payload;
}

function readRequestId(string $field = 'id'): int {
  $raw = $_GET[$field] ?? null;
  if (!is_scalar($raw) || !ctype_digit((string) $raw) || (int) $raw < 1) {
    jsonResponse(422, ['error' => "Missing or invalid {$field}"]);
  }

  return (int) $raw;
}

function ensureCsrfProtection(): void {
  $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  $csrfSession = $_SESSION['csrf_token'] ?? '';
  if (!is_string($csrfHeader) || !is_string($csrfSession) || !hash_equals($csrfSession, $csrfHeader)) {
    denyCsrf();
  }
}

function audit(PDO $pdo, array $session, string $action, string $targetType, ?int $targetId, string $result, array $metadata = []): void {
  $stmt = $pdo->prepare(
    'INSERT INTO calendar_sync_audit_log (owner_mailbox, actor, action, target_type, target_id, result, metadata_json)
     VALUES (:owner, :actor, :action, :target_type, :target_id, :result, :metadata_json)'
  );
  $stmt->execute([
    'owner' => auditOwnerMailbox($session),
    'actor' => actorIdentity($session),
    'action' => $action,
    'target_type' => $targetType,
    'target_id' => $targetId,
    'result' => $result,
    'metadata_json' => json_encode($metadata),
  ]);
}

function isDuplicateKeyException(Throwable $exception): bool {
  return $exception instanceof PDOException
    && ($exception->getCode() === '23000' || str_contains(strtolower($exception->getMessage()), 'duplicate'));
}

function getCryptoKeyMaterial(): string {
  $rawKey = getenv('MC_CALSYNC_CRYPTO_KEY');
  if (!is_string($rawKey) || trim($rawKey) === '') {
    jsonResponse(503, ['error' => 'Secure token storage is not configured. Set MC_CALSYNC_CRYPTO_KEY for php-fpm-mailcow and restart Mailcow.']);
  }

  return $rawKey;
}

function deriveCryptoKey(string $rawKey): string {
  $decoded = base64_decode($rawKey, true);
  if ($decoded !== false && strlen($decoded) >= 32) {
    return substr($decoded, 0, 32);
  }

  return hash('sha256', $rawKey, true);
}

function encryptSecret(string $plaintext): array {
  $key = deriveCryptoKey(getCryptoKeyMaterial());

  if (
    function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
    && defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES')
  ) {
    $nonceLength = (int) constant('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES');
    $nonce = random_bytes($nonceLength);
    $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $key);
    return [
      'ciphertext' => base64_encode($nonce . $ciphertext),
      'version' => 'xchacha20poly1305',
    ];
  }

  if (function_exists('openssl_encrypt')) {
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '');
    if ($ciphertext === false) {
      jsonResponse(503, ['error' => 'Secure token encryption failed']);
    }

    return [
      'ciphertext' => base64_encode($iv . $tag . $ciphertext),
      'version' => 'aes-256-gcm',
    ];
  }

  jsonResponse(503, ['error' => 'Secure token encryption is unavailable']);
}

function parseOptionalOauthTokens(array $input): ?array {
  if (!array_key_exists('oauth_tokens', $input)) {
    return null;
  }

  $tokens = $input['oauth_tokens'];
  if (!is_array($tokens)) {
    jsonResponse(422, ['error' => 'oauth_tokens must be an object']);
  }

  $accessToken = trim((string) ($tokens['access_token'] ?? ''));
  if ($accessToken === '') {
    jsonResponse(422, ['error' => 'oauth_tokens.access_token is required']);
  }

  $refreshToken = trim((string) ($tokens['refresh_token'] ?? ''));
  $expiresAt = null;
  if (isset($tokens['expires_at']) && trim((string) $tokens['expires_at']) !== '') {
    try {
      $expiresAt = (new DateTimeImmutable((string) $tokens['expires_at']))->format('Y-m-d H:i:s');
    } catch (Throwable $exception) {
      jsonResponse(422, ['error' => 'oauth_tokens.expires_at must be a valid datetime']);
    }
  }

  $scopes = $tokens['scopes'] ?? '';
  if (is_array($scopes)) {
    $scopes = implode(' ', array_map(static fn($scope): string => trim((string) $scope), $scopes));
  }
  $scopeString = trimToLength(trim((string) $scopes), 1000);

  $encryptedAccess = encryptSecret($accessToken);
  $encryptedRefresh = $refreshToken !== '' ? encryptSecret($refreshToken) : null;

  return [
    'encrypted_access_token' => $encryptedAccess['ciphertext'],
    'encrypted_refresh_token' => $encryptedRefresh['ciphertext'] ?? null,
    'token_expires_at' => $expiresAt,
    'scopes' => $scopeString,
    'crypto_key_version' => $encryptedAccess['version'],
  ];
}

function calendarSyncCurrentOrigin(): string {
  $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
  if (!is_string($host) || $host === '') {
    $host = 'localhost';
  }

  $scheme = isHttpsRequest() ? 'https' : 'http';
  return $scheme . '://' . $host;
}

function defaultRedirectUri(string $provider): string {
  return calendarSyncCurrentOrigin() . '/api/oauth/' . $provider . '/callback.php';
}

function defaultProviderScopes(string $provider): string {
  return $provider === 'google'
    ? 'openid email https://www.googleapis.com/auth/calendar'
    : 'offline_access openid profile email User.Read Calendars.ReadWrite';
}

function fetchProviderSettingRecord(PDO $pdo, string $provider): ?array {
  $stmt = $pdo->prepare(
    'SELECT id, provider, client_id, encrypted_client_secret, redirect_uri, scopes, tenant_id, enabled, configured_by, configured_at, updated_by, updated_at, crypto_key_version
     FROM calendar_sync_provider_settings
     WHERE provider = :provider'
  );
  $stmt->execute(['provider' => $provider]);
  $row = $stmt->fetch();
  return is_array($row) ? $row : null;
}

function buildProviderSettingPublic(string $provider, ?array $dbRow = null): array {
  $envClientId = getenv($provider === 'google' ? 'MC_CALSYNC_GOOGLE_CLIENT_ID' : 'MC_CALSYNC_MICROSOFT_CLIENT_ID');
  $envClientSecret = getenv($provider === 'google' ? 'MC_CALSYNC_GOOGLE_CLIENT_SECRET' : 'MC_CALSYNC_MICROSOFT_CLIENT_SECRET');
  $envRedirectUri = getenv($provider === 'google' ? 'MC_CALSYNC_GOOGLE_REDIRECT_URI' : 'MC_CALSYNC_MICROSOFT_REDIRECT_URI');
  $envScopes = getenv($provider === 'google' ? 'MC_CALSYNC_GOOGLE_SCOPES' : 'MC_CALSYNC_MICROSOFT_SCOPES');
  $envTenantId = $provider === 'microsoft' ? getenv('MC_CALSYNC_MICROSOFT_TENANT_ID') : null;

  $source = 'missing';
  $configured = false;
  $secretConfigured = false;
  $enabled = false;
  $clientId = null;
  $redirectUri = defaultRedirectUri($provider);
  $scopes = defaultProviderScopes($provider);
  $tenantId = $provider === 'microsoft' ? 'common' : null;
  $configuredBy = null;
  $configuredAt = null;
  $updatedAt = null;
  $updatedBy = null;

  if (is_array($dbRow)) {
    $source = 'database';
    $clientId = (string) $dbRow['client_id'];
    $secretConfigured = is_string($dbRow['encrypted_client_secret']) && trim($dbRow['encrypted_client_secret']) !== '';
    $configured = $clientId !== '' && $secretConfigured;
    $enabled = (bool) $dbRow['enabled'];
    $redirectUri = is_string($dbRow['redirect_uri']) && trim((string) $dbRow['redirect_uri']) !== ''
      ? (string) $dbRow['redirect_uri']
      : $redirectUri;
    $scopes = is_string($dbRow['scopes']) && trim((string) $dbRow['scopes']) !== ''
      ? (string) $dbRow['scopes']
      : $scopes;
    $tenantId = $provider === 'microsoft'
      ? (is_string($dbRow['tenant_id']) && trim((string) $dbRow['tenant_id']) !== '' ? (string) $dbRow['tenant_id'] : 'common')
      : null;
    $configuredBy = $dbRow['configured_by'];
    $configuredAt = $dbRow['configured_at'];
    $updatedAt = $dbRow['updated_at'];
    $updatedBy = $dbRow['updated_by'];
  } elseif (is_string($envClientId) && trim($envClientId) !== '' && is_string($envClientSecret) && trim($envClientSecret) !== '') {
    $source = 'environment';
    $configured = true;
    $secretConfigured = true;
    $enabled = true;
    $clientId = trim($envClientId);
    if (is_string($envRedirectUri) && trim($envRedirectUri) !== '') {
      $redirectUri = trim($envRedirectUri);
    }
    if (is_string($envScopes) && trim($envScopes) !== '') {
      $scopes = trim($envScopes);
    }
    if ($provider === 'microsoft' && is_string($envTenantId) && trim($envTenantId) !== '') {
      $tenantId = trim($envTenantId);
    }
    $configuredBy = 'environment';
  }

  return [
    'provider' => $provider,
    'provider_label' => providerLabel($provider),
    'source' => $source,
    'configured' => $configured,
    'enabled' => $enabled,
    'secret_configured' => $secretConfigured,
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'scopes' => $scopes,
    'tenant_id' => $tenantId,
    'configured_by' => $configuredBy,
    'configured_at' => $configuredAt,
    'updated_at' => $updatedAt,
    'updated_by' => $updatedBy,
    'status_label' => providerStatusLabel($provider, [
      'source' => $source,
      'secret_configured' => $secretConfigured,
      'enabled' => $enabled,
    ]),
    'available_for_users' => $configured && $enabled,
    'managed_by_environment' => $source === 'environment',
  ];
}

function selectProviderSettingsPublic(PDO $pdo): array {
  $items = [];
  foreach (['google', 'microsoft'] as $provider) {
    $items[] = buildProviderSettingPublic($provider, fetchProviderSettingRecord($pdo, $provider));
  }

  return $items;
}

function providerSettingApiView(array $item, bool $adminView): array {
  $view = [
    'provider' => $item['provider'],
    'provider_label' => $item['provider_label'],
    'source' => $item['source'],
    'configured' => $item['configured'],
    'enabled' => $item['enabled'],
    'secret_configured' => $item['secret_configured'],
    'status_label' => $item['status_label'],
    'available_for_users' => $item['available_for_users'],
    'managed_by_environment' => $item['managed_by_environment'],
    'can_remove' => $item['source'] === 'database',
  ];

  if ($adminView) {
    $view['client_id'] = $item['client_id'];
    $view['redirect_uri'] = $item['redirect_uri'];
    $view['scopes'] = $item['scopes'];
    $view['tenant_id'] = $item['tenant_id'];
    $view['configured_by'] = $item['configured_by'];
    $view['configured_at'] = $item['configured_at'];
    $view['updated_at'] = $item['updated_at'];
    $view['updated_by'] = $item['updated_by'];
  }

  return $view;
}

function providerSetupIndex(PDO $pdo): array {
  $index = [];
  foreach (selectProviderSettingsPublic($pdo) as $item) {
    $index[$item['provider']] = $item;
  }

  return $index;
}

function providerAvailableForUsers(array $providerSetupIndex, string $provider): bool {
  if (!array_key_exists($provider, $providerSetupIndex)) {
    return false;
  }

  return (bool) $providerSetupIndex[$provider]['available_for_users'];
}

function buildConnectUrl(string $provider, int $accountId, ?string $state, array $providerSetting): ?string {
  if ($state === null || $state === '' || !$providerSetting['available_for_users']) {
    return null;
  }

  $path = $provider === 'google'
    ? '/api/oauth/google/start.php'
    : '/api/oauth/microsoft/start.php';

  return sprintf('%s?account_id=%d&state=%s', $path, $accountId, rawurlencode($state));
}

function fetchAccountRecord(PDO $pdo, string $owner, int $accountId): ?array {
  $stmt = $pdo->prepare(
    'SELECT id, provider, provider_account_email, display_name, status, oauth_state
     FROM calendar_sync_accounts
     WHERE id = :id AND owner_mailbox = :owner'
  );
  $stmt->execute([
    'id' => $accountId,
    'owner' => $owner,
  ]);

  $row = $stmt->fetch();
  return is_array($row) ? $row : null;
}

function selectAccounts(PDO $pdo, string $owner, array $providerSetup): array {
  $stmt = $pdo->prepare(
    'SELECT id, provider, provider_account_email, display_name, status, created_at, connected_at, updated_at, oauth_state
     FROM calendar_sync_accounts
     WHERE owner_mailbox = :owner
     ORDER BY updated_at DESC'
  );
  $stmt->execute(['owner' => $owner]);

  $items = [];
  foreach ($stmt->fetchAll() as $row) {
    $provider = (string) $row['provider'];
    $setting = $providerSetup[$provider] ?? [
      'available_for_users' => false,
      'status_label' => 'Not configured',
    ];

    $items[] = [
      'id' => (int) $row['id'],
      'provider' => $provider,
      'provider_label' => providerLabel($provider),
      'provider_account_email' => (string) $row['provider_account_email'],
      'display_name' => (string) ($row['display_name'] ?? ''),
      'status' => (string) $row['status'],
      'created_at' => $row['created_at'],
      'connected_at' => $row['connected_at'],
      'updated_at' => $row['updated_at'],
      'oauth_ready' => (bool) $setting['available_for_users'],
      'oauth_connect_url' => buildConnectUrl($provider, (int) $row['id'], is_string($row['oauth_state']) ? $row['oauth_state'] : null, $setting),
      'provider_setup_status' => $setting['status_label'],
    ];
  }

  return $items;
}

function endpointSummary(array $endpoint): string {
  $summary = sprintf('%s - %s', providerLabel($endpoint['provider']), $endpoint['calendar_email']);
  if ($endpoint['calendar_id'] !== $endpoint['calendar_email']) {
    $summary .= sprintf(' (%s)', $endpoint['calendar_id']);
  }
  return $summary;
}

function canRunWithEndpointStatus(string $provider, ?string $accountStatus): bool {
  return $provider === 'mailcow' || $accountStatus === 'active';
}

function selectJobs(PDO $pdo, string $owner): array {
  $stmt = $pdo->prepare(
    'SELECT
       j.*,
       aa.provider_account_email AS endpoint_a_account_email,
       aa.status AS endpoint_a_account_status,
       bb.provider_account_email AS endpoint_b_account_email,
       bb.status AS endpoint_b_account_status
     FROM calendar_sync_jobs j
     LEFT JOIN calendar_sync_accounts aa ON aa.id = j.endpoint_a_account_id
     LEFT JOIN calendar_sync_accounts bb ON bb.id = j.endpoint_b_account_id
     WHERE j.owner_mailbox = :owner
     ORDER BY j.updated_at DESC, j.id DESC'
  );
  $stmt->execute(['owner' => $owner]);

  $items = [];
  foreach ($stmt->fetchAll() as $row) {
    $endpointAProvider = (string) $row['endpoint_a_provider'];
    $endpointBProvider = (string) $row['endpoint_b_provider'];
    $endpointAStatus = is_string($row['endpoint_a_account_status'] ?? null) ? (string) $row['endpoint_a_account_status'] : null;
    $endpointBStatus = is_string($row['endpoint_b_account_status'] ?? null) ? (string) $row['endpoint_b_account_status'] : null;

    $endpointA = [
      'provider' => $endpointAProvider,
      'provider_label' => providerLabel($endpointAProvider),
      'calendar_email' => (string) $row['endpoint_a_calendar_email'],
      'calendar_id' => (string) $row['endpoint_a_calendar_id'],
      'account_id' => isset($row['endpoint_a_account_id']) ? (int) $row['endpoint_a_account_id'] : null,
      'account_email' => $row['endpoint_a_account_email'],
      'account_status' => $endpointAStatus,
    ];

    $endpointB = [
      'provider' => $endpointBProvider,
      'provider_label' => providerLabel($endpointBProvider),
      'calendar_email' => (string) $row['endpoint_b_calendar_email'],
      'calendar_id' => (string) $row['endpoint_b_calendar_id'],
      'account_id' => isset($row['endpoint_b_account_id']) ? (int) $row['endpoint_b_account_id'] : null,
      'account_email' => $row['endpoint_b_account_email'],
      'account_status' => $endpointBStatus,
    ];

    $canEnable = canRunWithEndpointStatus($endpointAProvider, $endpointAStatus)
      && canRunWithEndpointStatus($endpointBProvider, $endpointBStatus);

    $items[] = [
      'id' => (int) $row['id'],
      'sync_label' => (string) $row['sync_label'],
      'sync_mode' => (string) $row['sync_mode'],
      'sync_mode_label' => syncModeLabel((string) $row['sync_mode']),
      'conflict_policy' => (string) $row['conflict_policy'],
      'interval_seconds' => (int) $row['interval_seconds'],
      'enabled' => (bool) $row['enabled'],
      'status' => (string) $row['status'],
      'status_label' => jobStatusLabel((string) $row['status']),
      'last_run_at' => $row['last_run_at'],
      'last_success_at' => $row['last_success_at'],
      'last_error_code' => $row['last_error_code'],
      'last_error_message' => $row['last_error_message'],
      'next_run_at' => $row['next_run_at'],
      'created_at' => $row['created_at'],
      'updated_at' => $row['updated_at'],
      'endpoint_a' => $endpointA,
      'endpoint_b' => $endpointB,
      'endpoint_a_summary' => endpointSummary($endpointA),
      'endpoint_b_summary' => endpointSummary($endpointB),
      'can_enable' => $canEnable,
    ];
  }

  return $items;
}

function buildEndpoint(PDO $pdo, string $owner, array $input, string $fieldName, array $providerSetup): array {
  $endpoint = $input[$fieldName] ?? null;
  if (!is_array($endpoint)) {
    jsonResponse(422, ['error' => "Missing object: {$fieldName}"]);
  }

  $provider = normalizeProvider((string) ($endpoint['provider'] ?? ''));
  $calendarEmail = normalizeEmail((string) ($endpoint['calendar_email'] ?? ''), "{$fieldName}.calendar_email");
  $calendarIdInput = trim((string) ($endpoint['calendar_id'] ?? ''));
  $calendarId = trimToLength($calendarIdInput !== '' ? $calendarIdInput : $calendarEmail, 255);

  $accountId = null;
  $accountStatus = null;
  $accountEmail = null;

  if ($provider === 'mailcow') {
    if ($calendarEmail !== $owner) {
      jsonResponse(403, ['error' => 'Mailcow endpoints can only use the authenticated mailbox']);
    }
  } else {
    if (!providerAvailableForUsers($providerSetup, $provider)) {
      jsonResponse(409, ['error' => sprintf('%s must be configured by an administrator before it can be used in syncs.', providerLabel($provider))]);
    }

    if (!array_key_exists('account_id', $endpoint) || !is_numeric((string) $endpoint['account_id'])) {
      jsonResponse(422, ['error' => "{$fieldName}.account_id is required for external providers"]);
    }

    $account = fetchAccountRecord($pdo, $owner, (int) $endpoint['account_id']);
    if ($account === null) {
      jsonResponse(403, ['error' => 'Invalid account ownership']);
    }
    if ((string) $account['provider'] !== $provider) {
      jsonResponse(422, ['error' => "{$fieldName}.account_id does not match the selected provider"]);
    }
    if (in_array((string) $account['status'], ['revoked', 'error'], true)) {
      jsonResponse(409, ['error' => 'Reconnect the selected external account before using it in a sync']);
    }

    $accountId = (int) $account['id'];
    $accountStatus = (string) $account['status'];
    $accountEmail = (string) $account['provider_account_email'];
  }

  return [
    'provider' => $provider,
    'calendar_email' => $calendarEmail,
    'calendar_id' => $calendarId,
    'account_id' => $accountId,
    'account_status' => $accountStatus,
    'account_email' => $accountEmail,
  ];
}

function endpointSignature(array $endpoint): string {
  return implode('|', [
    $endpoint['provider'],
    $endpoint['calendar_email'],
    $endpoint['calendar_id'],
    (string) ($endpoint['account_id'] ?? 0),
  ]);
}

function dedupeHashForJob(array $endpointA, array $endpointB, string $mode): string {
  $signatureA = endpointSignature($endpointA);
  $signatureB = endpointSignature($endpointB);

  if ($mode === 'two_way') {
    $pair = [$signatureA, $signatureB];
    sort($pair, SORT_STRING);
    return hash('sha256', 'two_way|' . $pair[0] . '|' . $pair[1]);
  }

  if ($mode === 'a_to_b') {
    return hash('sha256', 'one_way|' . $signatureA . '|' . $signatureB);
  }

  return hash('sha256', 'one_way|' . $signatureB . '|' . $signatureA);
}

function buildDefaultLabel(array $endpointA, array $endpointB, string $mode): string {
  return match ($mode) {
    'two_way' => trimToLength($endpointA['calendar_email'] . ' <-> ' . $endpointB['calendar_email'], 160),
    'a_to_b' => trimToLength($endpointA['calendar_email'] . ' -> ' . $endpointB['calendar_email'], 160),
    'b_to_a' => trimToLength($endpointB['calendar_email'] . ' -> ' . $endpointA['calendar_email'], 160),
    default => trimToLength($endpointA['calendar_email'] . ' sync', 160),
  };
}

function resolveInitialJobState(array $endpointA, array $endpointB): array {
  $canRunNow = canRunWithEndpointStatus($endpointA['provider'], $endpointA['account_status'])
    && canRunWithEndpointStatus($endpointB['provider'], $endpointB['account_status']);

  return [
    'enabled' => $canRunNow ? 1 : 0,
    'status' => $canRunNow ? 'idle' : 'awaiting_account',
    'next_run_at' => $canRunNow ? date('Y-m-d H:i:s', time() + 60) : null,
  ];
}

function resolveDatabaseConnection(): PDO {
  global $pdo;

  if ($pdo instanceof PDO) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
  }

  $dbDsn = getenv('MC_DB_DSN');
  $dbUser = getenv('MC_DB_USER');
  $dbPass = getenv('MC_DB_PASS');

  if (!is_string($dbDsn) || $dbDsn === '' || !is_string($dbUser) || $dbUser === '' || !is_string($dbPass) || $dbPass === '') {
    error_log('Missing Mailcow PDO bootstrap and required fallback DB environment variables: MC_DB_DSN, MC_DB_USER, and/or MC_DB_PASS');
    jsonResponse(503, ['error' => 'Service temporarily unavailable']);
  }

  try {
    return new PDO($dbDsn, $dbUser, $dbPass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  } catch (PDOException $exception) {
    internalErrorResponse('Database connection failed', $exception, 503);
  }
}

function upsertProviderSetting(PDO $pdo, array $session, array $input): array {
  requireAdmin($session);

  $provider = normalizeProvider((string) ($input['provider'] ?? ''), false);
  $clientId = normalizeOptionalString($input['client_id'] ?? null, 255);
  if ($clientId === null) {
    jsonResponse(422, ['error' => 'client_id is required']);
  }

  $clientSecret = normalizeOptionalString($input['client_secret'] ?? null, 4000);
  $enabled = array_key_exists('enabled', $input) ? normalizeBoolean($input['enabled'], 'enabled') : true;
  $redirectUri = normalizeOptionalString($input['redirect_uri'] ?? null, 512);
  $scopes = normalizeOptionalString($input['scopes'] ?? null, 1000);
  $tenantId = $provider === 'microsoft'
    ? normalizeOptionalString($input['tenant_id'] ?? null, 120)
    : null;

  $existing = fetchProviderSettingRecord($pdo, $provider);
  if ($existing === null && $clientSecret === null) {
    jsonResponse(422, ['error' => 'client_secret is required the first time a provider is configured']);
  }

  $encryptedSecret = null;
  $cryptoVersion = null;
  if ($clientSecret !== null) {
    $encrypted = encryptSecret($clientSecret);
    $encryptedSecret = $encrypted['ciphertext'];
    $cryptoVersion = $encrypted['version'];
  }

  $stmt = $pdo->prepare(
    'INSERT INTO calendar_sync_provider_settings (
       provider,
       client_id,
       encrypted_client_secret,
       redirect_uri,
       scopes,
       tenant_id,
       enabled,
       configured_by,
       configured_at,
       updated_by,
       crypto_key_version
     ) VALUES (
       :provider,
       :client_id,
       :encrypted_client_secret,
       :redirect_uri,
       :scopes,
       :tenant_id,
       :enabled,
       :configured_by,
       NOW(),
       :updated_by,
       :crypto_key_version
     )
     ON DUPLICATE KEY UPDATE
       client_id = VALUES(client_id),
       encrypted_client_secret = COALESCE(VALUES(encrypted_client_secret), encrypted_client_secret),
       redirect_uri = VALUES(redirect_uri),
       scopes = VALUES(scopes),
       tenant_id = VALUES(tenant_id),
       enabled = VALUES(enabled),
       updated_by = VALUES(updated_by),
       crypto_key_version = COALESCE(VALUES(crypto_key_version), crypto_key_version),
       updated_at = CURRENT_TIMESTAMP'
  );
  $stmt->execute([
    'provider' => $provider,
    'client_id' => $clientId,
    'encrypted_client_secret' => $encryptedSecret,
    'redirect_uri' => $redirectUri,
    'scopes' => $scopes,
    'tenant_id' => $tenantId,
    'enabled' => $enabled ? 1 : 0,
    'configured_by' => actorIdentity($session),
    'updated_by' => actorIdentity($session),
    'crypto_key_version' => $cryptoVersion,
  ]);

  $savedRecord = fetchProviderSettingRecord($pdo, $provider);
  $current = buildProviderSettingPublic($provider, $savedRecord);
  audit($pdo, $session, 'provider_setting_save', 'provider', isset($savedRecord['id']) ? (int) $savedRecord['id'] : null, 'success', [
    'provider' => $provider,
    'enabled' => $enabled,
    'secret_replaced' => $clientSecret !== null,
  ]);

  return $current;
}

function updateProviderSettingEnabled(PDO $pdo, array $session, string $provider, bool $enabled): array {
  requireAdmin($session);

  $record = fetchProviderSettingRecord($pdo, $provider);
  if ($record === null) {
    jsonResponse(404, ['error' => 'Provider setting not found']);
  }

  $stmt = $pdo->prepare(
    'UPDATE calendar_sync_provider_settings
     SET enabled = :enabled,
         updated_by = :updated_by
     WHERE provider = :provider'
  );
  $stmt->execute([
    'enabled' => $enabled ? 1 : 0,
    'updated_by' => actorIdentity($session),
    'provider' => $provider,
  ]);

  $current = buildProviderSettingPublic($provider, fetchProviderSettingRecord($pdo, $provider));
  audit($pdo, $session, $enabled ? 'provider_setting_enable' : 'provider_setting_disable', 'provider', (int) $record['id'], 'success', [
    'provider' => $provider,
    'enabled' => $enabled,
  ]);

  return $current;
}

function deleteProviderSetting(PDO $pdo, array $session, string $provider): void {
  requireAdmin($session);

  $record = fetchProviderSettingRecord($pdo, $provider);
  if ($record === null) {
    jsonResponse(404, ['error' => 'Provider setting not found']);
  }

  $stmt = $pdo->prepare('DELETE FROM calendar_sync_provider_settings WHERE provider = :provider');
  $stmt->execute(['provider' => $provider]);

  audit($pdo, $session, 'provider_setting_delete', 'provider', (int) $record['id'], 'success', [
    'provider' => $provider,
  ]);
}

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$session = resolveSessionContext();
if (!$session['authenticated']) {
  $debugPayload = authDebugEnabled() ? buildAuthDebugPayload($mailcowBootstrapPath, $mailcowBootstrapped, $session) : null;
  $payload = ['error' => 'Authentication required'];
  if ($debugPayload !== null) {
    $payload['debug'] = $debugPayload;
  }
  jsonResponse(401, $payload);
}

if (!$session['is_admin'] && !$session['can_manage_syncs']) {
  $payload = ['error' => 'This page requires a Mailcow mailbox user or global administrator session'];
  if (authDebugEnabled()) {
    $payload['debug'] = buildAuthDebugPayload($mailcowBootstrapPath, $mailcowBootstrapped, $session);
  }
  jsonResponse(403, $payload);
}

$pdo = resolveDatabaseConnection();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = strtolower(trim((string) ($_GET['path'] ?? 'dashboard')));

try {
  if ($method === 'GET' && $path === 'dashboard') {
    $providerSettings = array_map(
      static fn(array $item): array => providerSettingApiView($item, $session['is_admin']),
      selectProviderSettingsPublic($pdo)
    );
    $mailbox = $session['can_manage_syncs'] ? requireMailboxAccess($session) : null;

    jsonResponse(200, [
      'csrf_token' => $_SESSION['csrf_token'],
      'session' => [
        'authenticated' => true,
        'username' => $session['username'],
        'role' => $session['role'],
        'mailbox' => $session['mailbox'],
        'is_admin' => $session['is_admin'],
        'can_manage_syncs' => $session['can_manage_syncs'],
        'auth_method' => 'mailcow_session',
      ],
      'provider_options' => [
        ['id' => 'mailcow', 'label' => providerLabel('mailcow')],
        ['id' => 'google', 'label' => providerLabel('google')],
        ['id' => 'microsoft', 'label' => providerLabel('microsoft')],
      ],
      'provider_settings' => $providerSettings,
      'accounts' => $mailbox !== null ? selectAccounts($pdo, $mailbox, providerSetupIndex($pdo)) : [],
      'jobs' => $mailbox !== null ? selectJobs($pdo, $mailbox) : [],
    ]);
  }

  if ($method === 'GET' && $path === 'accounts') {
    $owner = requireMailboxAccess($session);
    jsonResponse(200, [
      'items' => selectAccounts($pdo, $owner, providerSetupIndex($pdo)),
      'csrf_token' => $_SESSION['csrf_token'],
    ]);
  }

  if ($method === 'GET' && $path === 'jobs') {
    $owner = requireMailboxAccess($session);
    jsonResponse(200, [
      'items' => selectJobs($pdo, $owner),
      'csrf_token' => $_SESSION['csrf_token'],
    ]);
  }

  if ($method === 'GET' && $path === 'provider-settings') {
    requireAdmin($session);
    jsonResponse(200, [
      'items' => array_map(
        static fn(array $item): array => providerSettingApiView($item, true),
        selectProviderSettingsPublic($pdo)
      ),
      'csrf_token' => $_SESSION['csrf_token'],
    ]);
  }

  if (in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
    ensureCsrfProtection();
  }

  if ($method === 'POST' && $path === 'provider-settings') {
    $current = upsertProviderSetting($pdo, $session, readJsonBody());
    jsonResponse(200, [
      'ok' => true,
      'item' => providerSettingApiView($current, true),
      'message' => sprintf('%s setup saved. Stored secrets are now hidden.', $current['provider_label']),
    ]);
  }

  if ($method === 'PATCH' && $path === 'provider-settings') {
    requireAdmin($session);
    $input = readJsonBody();
    $provider = normalizeProvider((string) ($input['provider'] ?? ''), false);
    $enabled = normalizeBoolean($input['enabled'] ?? null, 'enabled');
    $current = updateProviderSettingEnabled($pdo, $session, $provider, $enabled);
    jsonResponse(200, [
      'ok' => true,
      'item' => providerSettingApiView($current, true),
      'message' => sprintf('%s has been %s.', $current['provider_label'], $enabled ? 'enabled' : 'disabled'),
    ]);
  }

  if ($method === 'DELETE' && $path === 'provider-settings') {
    requireAdmin($session);
    $provider = normalizeProvider((string) ($_GET['provider'] ?? ''), false);
    deleteProviderSetting($pdo, $session, $provider);
    jsonResponse(200, [
      'ok' => true,
      'message' => sprintf('%s setup removed.', providerLabel($provider)),
    ]);
  }

  if ($method === 'POST' && $path === 'accounts') {
    $owner = requireMailboxAccess($session);
    $input = readJsonBody();
    $provider = normalizeProvider((string) ($input['provider'] ?? ''), false);
    $providerSetup = providerSetupIndex($pdo);
    if (!providerAvailableForUsers($providerSetup, $provider)) {
      jsonResponse(409, ['error' => sprintf('%s must be configured by an administrator before mailbox users can connect it.', providerLabel($provider))]);
    }

    $providerAccountEmail = normalizeEmail((string) ($input['provider_account_email'] ?? ''), 'provider_account_email');
    $displayName = trimToLength(trim((string) ($input['display_name'] ?? '')), 120);
    $oauthState = bin2hex(random_bytes(24));
    $tokenPayload = parseOptionalOauthTokens($input);
    $scopes = trimToLength(trim((string) ($input['scopes'] ?? '')), 1000);

    $existingStmt = $pdo->prepare(
      'SELECT id
       FROM calendar_sync_accounts
       WHERE owner_mailbox = :owner AND provider = :provider AND provider_account_email = :provider_account_email'
    );
    $existingStmt->execute([
      'owner' => $owner,
      'provider' => $provider,
      'provider_account_email' => $providerAccountEmail,
    ]);
    $existing = $existingStmt->fetch();

    try {
      $pdo->beginTransaction();

      if (is_array($existing)) {
        $accountId = (int) $existing['id'];
        if ($tokenPayload !== null) {
          $stmt = $pdo->prepare(
            'UPDATE calendar_sync_accounts
             SET display_name = :display_name,
                 encrypted_access_token = :encrypted_access_token,
                 encrypted_refresh_token = :encrypted_refresh_token,
                 token_expires_at = :token_expires_at,
                 scopes = :scopes,
                 oauth_state = :oauth_state,
                 crypto_key_version = :crypto_key_version,
                 status = :status,
                 last_error_message = NULL,
                 connected_at = COALESCE(connected_at, NOW())
             WHERE id = :id AND owner_mailbox = :owner'
          );
          $stmt->execute([
            'display_name' => $displayName !== '' ? $displayName : null,
            'encrypted_access_token' => $tokenPayload['encrypted_access_token'],
            'encrypted_refresh_token' => $tokenPayload['encrypted_refresh_token'],
            'token_expires_at' => $tokenPayload['token_expires_at'],
            'scopes' => $tokenPayload['scopes'] !== '' ? $tokenPayload['scopes'] : $scopes,
            'oauth_state' => $oauthState,
            'crypto_key_version' => $tokenPayload['crypto_key_version'],
            'status' => 'active',
            'id' => $accountId,
            'owner' => $owner,
          ]);
        } else {
          $stmt = $pdo->prepare(
            'UPDATE calendar_sync_accounts
             SET display_name = :display_name,
                 oauth_state = :oauth_state,
                 status = CASE WHEN status = \'active\' THEN \'active\' ELSE \'pending_oauth\' END,
                 last_error_message = NULL
             WHERE id = :id AND owner_mailbox = :owner'
          );
          $stmt->execute([
            'display_name' => $displayName !== '' ? $displayName : null,
            'oauth_state' => $oauthState,
            'id' => $accountId,
            'owner' => $owner,
          ]);
        }

        audit($pdo, $session, $tokenPayload !== null ? 'account_activate' : 'account_reconnect', 'account', $accountId, 'success', [
          'provider' => $provider,
          'provider_account_email' => $providerAccountEmail,
        ]);
      } else {
        $stmt = $pdo->prepare(
          'INSERT INTO calendar_sync_accounts (
             owner_mailbox,
             provider,
             provider_account_email,
             display_name,
             encrypted_access_token,
             encrypted_refresh_token,
             token_expires_at,
             scopes,
             oauth_state,
             crypto_key_version,
             status,
             connected_at
           ) VALUES (
             :owner_mailbox,
             :provider,
             :provider_account_email,
             :display_name,
             :encrypted_access_token,
             :encrypted_refresh_token,
             :token_expires_at,
             :scopes,
             :oauth_state,
             :crypto_key_version,
             :status,
             :connected_at
           )'
        );
        $stmt->execute([
          'owner_mailbox' => $owner,
          'provider' => $provider,
          'provider_account_email' => $providerAccountEmail,
          'display_name' => $displayName !== '' ? $displayName : null,
          'encrypted_access_token' => $tokenPayload['encrypted_access_token'] ?? null,
          'encrypted_refresh_token' => $tokenPayload['encrypted_refresh_token'] ?? null,
          'token_expires_at' => $tokenPayload['token_expires_at'] ?? null,
          'scopes' => ($tokenPayload['scopes'] ?? $scopes) !== '' ? ($tokenPayload['scopes'] ?? $scopes) : null,
          'oauth_state' => $oauthState,
          'crypto_key_version' => $tokenPayload['crypto_key_version'] ?? null,
          'status' => $tokenPayload !== null ? 'active' : 'pending_oauth',
          'connected_at' => $tokenPayload !== null ? date('Y-m-d H:i:s') : null,
        ]);

        $accountId = (int) $pdo->lastInsertId();
        audit($pdo, $session, $tokenPayload !== null ? 'account_activate' : 'account_connect', 'account', $accountId, 'success', [
          'provider' => $provider,
          'provider_account_email' => $providerAccountEmail,
        ]);
      }

      $pdo->commit();
    } catch (Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }

    $accounts = selectAccounts($pdo, $owner, $providerSetup);
    $accountView = array_values(array_filter($accounts, static fn(array $account): bool => $account['id'] === $accountId));
    jsonResponse(200, [
      'ok' => true,
      'account' => $accountView[0] ?? null,
      'message' => $tokenPayload !== null
        ? 'External account connected and encrypted successfully.'
        : 'External account saved. Continue OAuth from the table below.',
    ]);
  }

  if ($method === 'POST' && $path === 'jobs') {
    $owner = requireMailboxAccess($session);
    $providerSetup = providerSetupIndex($pdo);
    $input = readJsonBody();

    $endpointA = buildEndpoint($pdo, $owner, $input, 'endpoint_a', $providerSetup);
    $endpointB = buildEndpoint($pdo, $owner, $input, 'endpoint_b', $providerSetup);
    $syncMode = normalizeMode((string) ($input['sync_mode'] ?? 'two_way'));
    $conflictPolicy = normalizeConflictPolicy((string) ($input['conflict_policy'] ?? 'newest_wins'));
    $intervalSeconds = max(60, min(3600, (int) ($input['interval_seconds'] ?? 300)));
    $syncLabelInput = trim((string) ($input['sync_label'] ?? ''));
    $syncLabel = trimToLength($syncLabelInput !== '' ? $syncLabelInput : buildDefaultLabel($endpointA, $endpointB, $syncMode), 160);

    if ($endpointA['provider'] !== 'mailcow' && $endpointB['provider'] !== 'mailcow') {
      jsonResponse(422, ['error' => 'At least one endpoint must be the authenticated Mailcow calendar']);
    }

    if (endpointSignature($endpointA) === endpointSignature($endpointB)) {
      jsonResponse(422, ['error' => 'Calendar A and Calendar B must be different']);
    }

    $dedupeHash = dedupeHashForJob($endpointA, $endpointB, $syncMode);
    $initialState = resolveInitialJobState($endpointA, $endpointB);

    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare(
        'INSERT INTO calendar_sync_jobs (
           owner_mailbox,
           sync_label,
           dedupe_hash,
           sync_mode,
           conflict_policy,
           interval_seconds,
           enabled,
           status,
           endpoint_a_provider,
           endpoint_a_calendar_email,
           endpoint_a_calendar_id,
           endpoint_a_account_id,
           endpoint_b_provider,
           endpoint_b_calendar_email,
           endpoint_b_calendar_id,
           endpoint_b_account_id,
           next_run_at
         ) VALUES (
           :owner_mailbox,
           :sync_label,
           :dedupe_hash,
           :sync_mode,
           :conflict_policy,
           :interval_seconds,
           :enabled,
           :status,
           :endpoint_a_provider,
           :endpoint_a_calendar_email,
           :endpoint_a_calendar_id,
           :endpoint_a_account_id,
           :endpoint_b_provider,
           :endpoint_b_calendar_email,
           :endpoint_b_calendar_id,
           :endpoint_b_account_id,
           :next_run_at
         )'
      );
      $stmt->execute([
        'owner_mailbox' => $owner,
        'sync_label' => $syncLabel,
        'dedupe_hash' => $dedupeHash,
        'sync_mode' => $syncMode,
        'conflict_policy' => $conflictPolicy,
        'interval_seconds' => $intervalSeconds,
        'enabled' => $initialState['enabled'],
        'status' => $initialState['status'],
        'endpoint_a_provider' => $endpointA['provider'],
        'endpoint_a_calendar_email' => $endpointA['calendar_email'],
        'endpoint_a_calendar_id' => $endpointA['calendar_id'],
        'endpoint_a_account_id' => $endpointA['account_id'],
        'endpoint_b_provider' => $endpointB['provider'],
        'endpoint_b_calendar_email' => $endpointB['calendar_email'],
        'endpoint_b_calendar_id' => $endpointB['calendar_id'],
        'endpoint_b_account_id' => $endpointB['account_id'],
        'next_run_at' => $initialState['next_run_at'],
      ]);

      $jobId = (int) $pdo->lastInsertId();
      audit($pdo, $session, 'job_create', 'job', $jobId, 'success', [
        'sync_mode' => $syncMode,
        'endpoint_a' => $endpointA['calendar_email'],
        'endpoint_b' => $endpointB['calendar_email'],
      ]);
      $pdo->commit();
    } catch (Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      if (isDuplicateKeyException($exception)) {
        jsonResponse(409, ['error' => 'A sync with the same calendars and direction already exists']);
      }

      throw $exception;
    }

    $jobs = selectJobs($pdo, $owner);
    $jobView = array_values(array_filter($jobs, static fn(array $job): bool => $job['id'] === $jobId));
    jsonResponse(201, [
      'ok' => true,
      'job' => $jobView[0] ?? null,
      'message' => $initialState['status'] === 'awaiting_account'
        ? 'Sync saved. Complete OAuth for the external calendar account before enabling it.'
        : 'Sync created successfully.',
    ]);
  }

  if ($method === 'PATCH' && $path === 'jobs') {
    $owner = requireMailboxAccess($session);
    $jobId = readRequestId();
    $input = readJsonBody();
    $enableRequested = normalizeBoolean($input['enabled'] ?? null, 'enabled');

    $jobs = selectJobs($pdo, $owner);
    $jobView = array_values(array_filter($jobs, static fn(array $job): bool => $job['id'] === $jobId));
    if ($jobView === []) {
      jsonResponse(404, ['error' => 'Sync job not found']);
    }

    $job = $jobView[0];
    if ($enableRequested && !$job['can_enable']) {
      jsonResponse(409, ['error' => 'Complete OAuth for every external account before enabling this sync']);
    }

    $nextStatus = $enableRequested ? 'idle' : 'paused';
    $nextRunAt = $enableRequested ? date('Y-m-d H:i:s', time() + 60) : null;

    $stmt = $pdo->prepare(
      'UPDATE calendar_sync_jobs
       SET enabled = :enabled,
           status = :status,
           next_run_at = :next_run_at
       WHERE id = :id AND owner_mailbox = :owner'
    );
    $stmt->execute([
      'enabled' => $enableRequested ? 1 : 0,
      'status' => $nextStatus,
      'next_run_at' => $nextRunAt,
      'id' => $jobId,
      'owner' => $owner,
    ]);

    audit($pdo, $session, $enableRequested ? 'job_enable' : 'job_pause', 'job', $jobId, 'success', [
      'enabled' => $enableRequested,
    ]);

    $updatedJobs = selectJobs($pdo, $owner);
    $updatedView = array_values(array_filter($updatedJobs, static fn(array $job): bool => $job['id'] === $jobId));
    jsonResponse(200, ['ok' => true, 'job' => $updatedView[0] ?? null]);
  }

  if ($method === 'DELETE' && $path === 'jobs') {
    $owner = requireMailboxAccess($session);
    $jobId = readRequestId();
    $jobs = selectJobs($pdo, $owner);
    $jobView = array_values(array_filter($jobs, static fn(array $job): bool => $job['id'] === $jobId));
    if ($jobView === []) {
      jsonResponse(404, ['error' => 'Sync job not found']);
    }

    try {
      $pdo->beginTransaction();
      $stmt = $pdo->prepare('DELETE FROM calendar_sync_jobs WHERE id = :id AND owner_mailbox = :owner');
      $stmt->execute([
        'id' => $jobId,
        'owner' => $owner,
      ]);
      audit($pdo, $session, 'job_delete', 'job', $jobId, 'success');
      $pdo->commit();
    } catch (Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $exception;
    }

    jsonResponse(200, ['ok' => true]);
  }

  jsonResponse(405, ['error' => 'Method not allowed']);
} catch (PDOException $exception) {
  internalErrorResponse('Database request failed', $exception, 503);
} catch (Throwable $exception) {
  internalErrorResponse('Unhandled request error', $exception, 500);
}
