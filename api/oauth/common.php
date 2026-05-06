<?php
declare(strict_types=1);

function calendarSyncIsHttpsRequest(): bool {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    return true;
  }

  $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
  return is_string($forwardedProto) && strtolower($forwardedProto) === 'https';
}

function calendarSyncTrimToLength(string $value, int $maxLength): string {
  if (function_exists('mb_substr')) {
    return mb_substr($value, 0, $maxLength);
  }

  return substr($value, 0, $maxLength);
}

function calendarSyncDetectMailcowBootstrapPath(): ?string {
  $candidates = [
    __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'prerequisites.inc.php',
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

function calendarSyncBootstrapSession(): void {
  static $bootstrapped = false;

  if ($bootstrapped) {
    return;
  }

  $bootstrapPath = calendarSyncDetectMailcowBootstrapPath();
  if ($bootstrapPath !== null) {
    require_once $bootstrapPath;
  }

  ini_set('session.use_strict_mode', '1');

  if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
      'httponly' => true,
      'secure' => calendarSyncIsHttpsRequest(),
      'samesite' => 'Lax',
    ]);
  }

  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }

  $bootstrapped = true;
}

function calendarSyncResolveAuthenticatedMailbox(): ?string {
  $mailcowUsername = $_SESSION['mailcow_cc_username'] ?? null;
  $mailcowRole = $_SESSION['mailcow_cc_role'] ?? null;
  if (is_string($mailcowUsername) && $mailcowUsername !== '' && is_string($mailcowRole) && $mailcowRole !== '') {
    $candidate = strtolower(trim($mailcowUsername));
    if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
      return calendarSyncTrimToLength($candidate, 255);
    }
  }

  $candidates = [
    $_SESSION['mailcow_user'] ?? null,
    $_SESSION['mailcow_cc_mailbox'] ?? null,
    $_SESSION['login_user'] ?? null,
  ];

  foreach ($candidates as $value) {
    if (!is_string($value)) {
      continue;
    }

    $candidate = strtolower(trim($value));
    if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
      return calendarSyncTrimToLength($candidate, 255);
    }
  }

  return null;
}

function calendarSyncRequireMailboxUser(): string {
  $owner = calendarSyncResolveAuthenticatedMailbox();
  if ($owner === null) {
    calendarSyncRenderHtmlPage(401, 'Authentication required', 'Open this page from an active Mailcow mailbox session.');
  }

  return $owner;
}

function calendarSyncResolveDatabaseConnection(): PDO {
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
    calendarSyncRenderHtmlPage(503, 'Database unavailable', 'Mailcow did not expose a PDO connection and fallback DB credentials are missing.');
  }

  try {
    return new PDO($dbDsn, $dbUser, $dbPass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  } catch (PDOException $exception) {
    error_log(sprintf('Calendar sync DB connection failed: %s in %s:%d', $exception->getMessage(), $exception->getFile(), $exception->getLine()));
    calendarSyncRenderHtmlPage(503, 'Database unavailable', 'Mailcow calendar sync could not connect to the database.');
  }
}

function calendarSyncProviderLabel(string $provider): string {
  return match ($provider) {
    'google' => 'Google Calendar',
    'microsoft' => 'Outlook / Microsoft 365',
    'mailcow' => 'Mailcow',
    default => ucfirst($provider),
  };
}

function calendarSyncCurrentOrigin(): string {
  $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
  if (!is_string($host) || $host === '') {
    $host = 'localhost';
  }

  $scheme = calendarSyncIsHttpsRequest() ? 'https' : 'http';
  return $scheme . '://' . $host;
}

function calendarSyncBuildRedirectUri(string $provider): string {
  $envName = $provider === 'google'
    ? 'MC_CALSYNC_GOOGLE_REDIRECT_URI'
    : 'MC_CALSYNC_MICROSOFT_REDIRECT_URI';

  $configured = getenv($envName);
  if (is_string($configured) && trim($configured) !== '') {
    return trim($configured);
  }

  return calendarSyncCurrentOrigin() . '/api/oauth/' . $provider . '/callback.php';
}

function calendarSyncDefaultProviderScopes(string $provider): string {
  return $provider === 'google'
    ? 'openid email https://www.googleapis.com/auth/calendar'
    : 'offline_access openid profile email User.Read Calendars.ReadWrite';
}

function calendarSyncFetchProviderSettingRecord(PDO $pdo, string $provider): ?array {
  $stmt = $pdo->prepare(
    'SELECT id, provider, client_id, encrypted_client_secret, redirect_uri, scopes, tenant_id, enabled, crypto_key_version
     FROM calendar_sync_provider_settings
     WHERE provider = :provider'
  );
  $stmt->execute(['provider' => $provider]);
  $row = $stmt->fetch();
  return is_array($row) ? $row : null;
}

function calendarSyncResolveProviderConfiguration(PDO $pdo, string $provider): array {
  $dbRecord = calendarSyncFetchProviderSettingRecord($pdo, $provider);
  $defaultRedirectUri = calendarSyncCurrentOrigin() . '/api/oauth/' . $provider . '/callback.php';
  $defaultScopes = calendarSyncDefaultProviderScopes($provider);
  $defaultTenantId = $provider === 'microsoft' ? 'common' : null;

  if (is_array($dbRecord)) {
    $clientId = trim((string) ($dbRecord['client_id'] ?? ''));
    $encryptedSecret = trim((string) ($dbRecord['encrypted_client_secret'] ?? ''));
    $enabled = (bool) ($dbRecord['enabled'] ?? false);

    return [
      'source' => 'database',
      'configured' => $clientId !== '' && $encryptedSecret !== '',
      'enabled' => $enabled,
      'available' => $enabled && $clientId !== '' && $encryptedSecret !== '',
      'client_id' => $clientId,
      'client_secret' => $encryptedSecret !== ''
        ? calendarSyncDecryptSecret($encryptedSecret, is_string($dbRecord['crypto_key_version'] ?? null) ? (string) $dbRecord['crypto_key_version'] : null)
        : '',
      'redirect_uri' => is_string($dbRecord['redirect_uri'] ?? null) && trim((string) $dbRecord['redirect_uri']) !== ''
        ? trim((string) $dbRecord['redirect_uri'])
        : $defaultRedirectUri,
      'scopes' => is_string($dbRecord['scopes'] ?? null) && trim((string) $dbRecord['scopes']) !== ''
        ? trim((string) $dbRecord['scopes'])
        : $defaultScopes,
      'tenant_id' => $provider === 'microsoft'
        ? (is_string($dbRecord['tenant_id'] ?? null) && trim((string) $dbRecord['tenant_id']) !== '' ? trim((string) $dbRecord['tenant_id']) : 'common')
        : null,
    ];
  }

  $clientIdEnv = $provider === 'google'
    ? getenv('MC_CALSYNC_GOOGLE_CLIENT_ID')
    : getenv('MC_CALSYNC_MICROSOFT_CLIENT_ID');
  $clientSecretEnv = $provider === 'google'
    ? getenv('MC_CALSYNC_GOOGLE_CLIENT_SECRET')
    : getenv('MC_CALSYNC_MICROSOFT_CLIENT_SECRET');
  $redirectUriEnv = $provider === 'google'
    ? getenv('MC_CALSYNC_GOOGLE_REDIRECT_URI')
    : getenv('MC_CALSYNC_MICROSOFT_REDIRECT_URI');
  $scopesEnv = $provider === 'google'
    ? getenv('MC_CALSYNC_GOOGLE_SCOPES')
    : getenv('MC_CALSYNC_MICROSOFT_SCOPES');
  $tenantEnv = $provider === 'microsoft'
    ? getenv('MC_CALSYNC_MICROSOFT_TENANT_ID')
    : null;

  $clientId = is_string($clientIdEnv) ? trim($clientIdEnv) : '';
  $clientSecret = is_string($clientSecretEnv) ? trim($clientSecretEnv) : '';

  return [
    'source' => 'environment',
    'configured' => $clientId !== '' && $clientSecret !== '',
    'enabled' => $clientId !== '' && $clientSecret !== '',
    'available' => $clientId !== '' && $clientSecret !== '',
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => is_string($redirectUriEnv) && trim($redirectUriEnv) !== '' ? trim($redirectUriEnv) : $defaultRedirectUri,
    'scopes' => is_string($scopesEnv) && trim($scopesEnv) !== '' ? trim($scopesEnv) : $defaultScopes,
    'tenant_id' => $provider === 'microsoft'
      ? (is_string($tenantEnv) && trim($tenantEnv) !== '' ? trim($tenantEnv) : $defaultTenantId)
      : null,
  ];
}

function calendarSyncBuildReturnUrl(array $params = []): string {
  $base = calendarSyncCurrentOrigin() . '/calendar_sync.html';
  if ($params === []) {
    return $base;
  }

  return $base . '?' . http_build_query($params);
}

function calendarSyncRedirect(string $url): void {
  header('Cache-Control: no-store, no-cache, must-revalidate');
  header('Pragma: no-cache');
  header('Location: ' . $url, true, 302);
  exit;
}

function calendarSyncRenderHtmlPage(int $statusCode, string $title, string $message, array $details = []): void {
  http_response_code($statusCode);
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  header('Pragma: no-cache');

  $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
  $escapedMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
  $detailMarkup = '';

  if ($details !== []) {
    $items = [];
    foreach ($details as $detail) {
      $items[] = '<li>' . htmlspecialchars((string) $detail, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $detailMarkup = '<ul>' . implode('', $items) . '</ul>';
  }

  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' .
    $escapedTitle .
    '</title><style>body{font-family:Segoe UI,Arial,sans-serif;background:#f3f6f1;color:#17322b;margin:0;padding:2rem}.card{max-width:760px;margin:2rem auto;background:#fff;border:1px solid #dce7e1;border-radius:18px;padding:1.5rem 1.75rem;box-shadow:0 18px 40px rgba(26,61,54,.08)}h1{margin:0 0 .75rem;font-size:1.8rem}p{line-height:1.6;color:#526861}a{color:#125649;font-weight:700;text-decoration:none}ul{color:#526861;line-height:1.6}</style></head><body><div class="card"><h1>' .
    $escapedTitle .
    '</h1><p>' .
    $escapedMessage .
    '</p>' .
    $detailMarkup .
    '<p><a href="' . htmlspecialchars(calendarSyncBuildReturnUrl(), ENT_QUOTES, 'UTF-8') . '">Return to Calendar Sync</a></p></div></body></html>';
  exit;
}

function calendarSyncRegisterErrorHandlers(): void {
  static $registered = false;

  if ($registered) {
    return;
  }

  set_exception_handler(static function (Throwable $exception): void {
    error_log(sprintf(
      'Calendar sync OAuth exception: %s in %s:%d',
      $exception->getMessage(),
      $exception->getFile(),
      $exception->getLine()
    ));

    if (!headers_sent()) {
      calendarSyncRenderHtmlPage(
        500,
        'Calendar sync OAuth error',
        'The provider connection could not be started because the server hit an internal error.',
        [
          'Check php-fpm-mailcow logs for the matching "Calendar sync OAuth exception" entry.',
        ]
      );
    }
  });

  register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!is_array($error)) {
      return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'] ?? null, $fatalTypes, true)) {
      return;
    }

    error_log(sprintf(
      'Calendar sync OAuth fatal error: %s in %s:%d',
      (string) ($error['message'] ?? 'unknown error'),
      (string) ($error['file'] ?? 'unknown file'),
      (int) ($error['line'] ?? 0)
    ));

    if (!headers_sent()) {
      http_response_code(500);
      header('Content-Type: text/html; charset=utf-8');
      header('Cache-Control: no-store, no-cache, must-revalidate');
      header('Pragma: no-cache');
      echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Calendar sync OAuth error</title><style>body{font-family:Segoe UI,Arial,sans-serif;background:#f3f6f1;color:#17322b;margin:0;padding:2rem}.card{max-width:760px;margin:2rem auto;background:#fff;border:1px solid #dce7e1;border-radius:18px;padding:1.5rem 1.75rem;box-shadow:0 18px 40px rgba(26,61,54,.08)}h1{margin:0 0 .75rem;font-size:1.8rem}p{line-height:1.6;color:#526861}a{color:#125649;font-weight:700;text-decoration:none}</style></head><body><div class="card"><h1>Calendar sync OAuth error</h1><p>The provider connection could not be started because PHP hit a fatal error.</p><p>Check <strong>php-fpm-mailcow</strong> logs for the matching <code>Calendar sync OAuth fatal error</code> entry.</p><p><a href="' . htmlspecialchars(calendarSyncBuildReturnUrl(), ENT_QUOTES, 'UTF-8') . '">Return to Calendar Sync</a></p></div></body></html>';
    }
  });

  $registered = true;
}

function calendarSyncGetRequiredInt(string $name): int {
  $raw = $_GET[$name] ?? null;
  if (!is_scalar($raw) || !ctype_digit((string) $raw) || (int) $raw < 1) {
    calendarSyncRenderHtmlPage(422, 'Invalid request', sprintf('Missing or invalid "%s".', $name));
  }

  return (int) $raw;
}

function calendarSyncGetRequiredString(string $name): string {
  $raw = $_GET[$name] ?? null;
  if (!is_scalar($raw) || trim((string) $raw) === '') {
    calendarSyncRenderHtmlPage(422, 'Invalid request', sprintf('Missing or invalid "%s".', $name));
  }

  return trim((string) $raw);
}

function calendarSyncFetchOwnedAccount(PDO $pdo, string $owner, int $accountId, string $provider): ?array {
  $stmt = $pdo->prepare(
    'SELECT id, owner_mailbox, provider, provider_account_email, display_name, encrypted_refresh_token, status, oauth_state
     FROM calendar_sync_accounts
     WHERE id = :id AND owner_mailbox = :owner AND provider = :provider'
  );
  $stmt->execute([
    'id' => $accountId,
    'owner' => $owner,
    'provider' => $provider,
  ]);

  $row = $stmt->fetch();
  return is_array($row) ? $row : null;
}

function calendarSyncStoreOauthAttempt(string $provider, string $state, string $owner, int $accountId): void {
  if (!isset($_SESSION['calendar_sync_oauth']) || !is_array($_SESSION['calendar_sync_oauth'])) {
    $_SESSION['calendar_sync_oauth'] = [];
  }
  if (!isset($_SESSION['calendar_sync_oauth'][$provider]) || !is_array($_SESSION['calendar_sync_oauth'][$provider])) {
    $_SESSION['calendar_sync_oauth'][$provider] = [];
  }

  $_SESSION['calendar_sync_oauth'][$provider][$state] = [
    'owner' => $owner,
    'account_id' => $accountId,
    'created_at' => time(),
  ];
}

function calendarSyncConsumeOauthAttempt(string $provider, string $state): ?array {
  $attempt = $_SESSION['calendar_sync_oauth'][$provider][$state] ?? null;
  if (isset($_SESSION['calendar_sync_oauth'][$provider][$state])) {
    unset($_SESSION['calendar_sync_oauth'][$provider][$state]);
  }

  return is_array($attempt) ? $attempt : null;
}

function calendarSyncGetCryptoKeyMaterial(): string {
  $rawKey = getenv('MC_CALSYNC_CRYPTO_KEY');
  if (!is_string($rawKey) || trim($rawKey) === '') {
    calendarSyncRenderHtmlPage(503, 'Secure token storage unavailable', 'Set MC_CALSYNC_CRYPTO_KEY before completing OAuth.');
  }

  return $rawKey;
}

function calendarSyncDeriveCryptoKey(string $rawKey): string {
  $decoded = base64_decode($rawKey, true);
  if ($decoded !== false && strlen($decoded) >= 32) {
    return substr($decoded, 0, 32);
  }

  return hash('sha256', $rawKey, true);
}

function calendarSyncEncryptSecret(string $plaintext): array {
  $key = calendarSyncDeriveCryptoKey(calendarSyncGetCryptoKeyMaterial());

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
      calendarSyncRenderHtmlPage(503, 'Secure token storage unavailable', 'OpenSSL could not encrypt the provider token.');
    }

    return [
      'ciphertext' => base64_encode($iv . $tag . $ciphertext),
      'version' => 'aes-256-gcm',
    ];
  }

  calendarSyncRenderHtmlPage(503, 'Secure token storage unavailable', 'Neither Sodium nor OpenSSL token encryption is available.');
}

function calendarSyncDecryptSecret(string $encodedCiphertext, ?string $version): string {
  $payload = base64_decode($encodedCiphertext, true);
  if ($payload === false || $payload === '') {
    calendarSyncRenderHtmlPage(503, 'Secure token storage unavailable', 'Stored provider credentials could not be decoded.');
  }

  $key = calendarSyncDeriveCryptoKey(calendarSyncGetCryptoKeyMaterial());
  $cipherVersion = $version !== null && $version !== '' ? $version : 'aes-256-gcm';

  if (
    $cipherVersion === 'xchacha20poly1305'
    && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')
    && defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES')
  ) {
    $nonceLength = (int) constant('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES');
    if (strlen($payload) <= $nonceLength) {
      calendarSyncRenderHtmlPage(503, 'Secure token storage unavailable', 'Stored provider credentials are incomplete.');
    }

    $nonce = substr($payload, 0, $nonceLength);
    $ciphertext = substr($payload, $nonceLength);
    $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, '', $nonce, $key);
    if ($plaintext === false) {
      calendarSyncRenderHtmlPage(503, 'Secure token storage unavailable', 'Stored provider credentials could not be decrypted.');
    }

    return $plaintext;
  }

  if ($cipherVersion === 'aes-256-gcm' && function_exists('openssl_decrypt')) {
    if (strlen($payload) <= 28) {
      calendarSyncRenderHtmlPage(503, 'Secure token storage unavailable', 'Stored provider credentials are incomplete.');
    }

    $iv = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '');
    if ($plaintext === false) {
      calendarSyncRenderHtmlPage(503, 'Secure token storage unavailable', 'Stored provider credentials could not be decrypted.');
    }

    return $plaintext;
  }

  calendarSyncRenderHtmlPage(503, 'Secure token storage unavailable', 'Stored provider credentials use an unsupported encryption format.');
}

function calendarSyncHttpPostForm(string $url, array $payload): array {
  if (!function_exists('curl_init')) {
    calendarSyncRenderHtmlPage(503, 'OAuth transport unavailable', 'PHP cURL is required to exchange OAuth authorization codes.');
  }

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT => 20,
  ]);

  $body = curl_exec($ch);
  if ($body === false) {
    $error = curl_error($ch);
    curl_close($ch);
    return [
      'status' => 0,
      'body' => '',
      'json' => null,
      'error' => $error,
    ];
  }

  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $json = json_decode($body, true);
  return [
    'status' => $status,
    'body' => $body,
    'json' => is_array($json) ? $json : null,
    'error' => null,
  ];
}

function calendarSyncUpdateAccountTokens(
  PDO $pdo,
  string $owner,
  int $accountId,
  string $accessToken,
  ?string $refreshToken,
  ?string $scopes,
  ?int $expiresIn,
  ?string $existingEncryptedRefreshToken
): void {
  $encryptedAccess = calendarSyncEncryptSecret($accessToken);
  $encryptedRefresh = $refreshToken !== null && $refreshToken !== ''
    ? calendarSyncEncryptSecret($refreshToken)
    : null;

  $expiresAt = null;
  if ($expiresIn !== null && $expiresIn > 0) {
    $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
  }

  $stmt = $pdo->prepare(
    'UPDATE calendar_sync_accounts
     SET encrypted_access_token = :encrypted_access_token,
         encrypted_refresh_token = :encrypted_refresh_token,
         token_expires_at = :token_expires_at,
         scopes = :scopes,
         crypto_key_version = :crypto_key_version,
         status = :status,
         last_error_message = NULL,
         connected_at = NOW(),
         oauth_state = :oauth_state
     WHERE id = :id AND owner_mailbox = :owner'
  );
  $stmt->execute([
    'encrypted_access_token' => $encryptedAccess['ciphertext'],
    'encrypted_refresh_token' => $encryptedRefresh['ciphertext'] ?? $existingEncryptedRefreshToken,
    'token_expires_at' => $expiresAt,
    'scopes' => $scopes !== null && trim($scopes) !== '' ? calendarSyncTrimToLength(trim($scopes), 1000) : null,
    'crypto_key_version' => $encryptedAccess['version'],
    'status' => 'active',
    'oauth_state' => bin2hex(random_bytes(24)),
    'id' => $accountId,
    'owner' => $owner,
  ]);
}

function calendarSyncMarkAccountError(PDO $pdo, string $owner, int $accountId, string $message): void {
  $stmt = $pdo->prepare(
    'UPDATE calendar_sync_accounts
     SET status = :status,
         last_error_message = :last_error_message
     WHERE id = :id AND owner_mailbox = :owner'
  );
  $stmt->execute([
    'status' => 'error',
    'last_error_message' => calendarSyncTrimToLength($message, 512),
    'id' => $accountId,
    'owner' => $owner,
  ]);
}

function calendarSyncAudit(PDO $pdo, string $owner, string $action, string $targetType, ?int $targetId, string $result, array $metadata = []): void {
  $stmt = $pdo->prepare(
    'INSERT INTO calendar_sync_audit_log (owner_mailbox, actor, action, target_type, target_id, result, metadata_json)
     VALUES (:owner, :actor, :action, :target_type, :target_id, :result, :metadata_json)'
  );
  $stmt->execute([
    'owner' => $owner,
    'actor' => $owner,
    'action' => $action,
    'target_type' => $targetType,
    'target_id' => $targetId,
    'result' => $result,
    'metadata_json' => json_encode($metadata),
  ]);
}

function calendarSyncResumeReadyJobs(PDO $pdo, string $owner): void {
  $stmt = $pdo->prepare(
    'UPDATE calendar_sync_jobs j
     LEFT JOIN calendar_sync_accounts aa ON aa.id = j.endpoint_a_account_id
     LEFT JOIN calendar_sync_accounts bb ON bb.id = j.endpoint_b_account_id
     SET j.enabled = 1,
         j.status = \'idle\',
         j.next_run_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE),
         j.last_error_code = NULL,
         j.last_error_message = NULL
     WHERE j.owner_mailbox = :owner
       AND j.status = \'awaiting_account\'
       AND (j.endpoint_a_provider = \'mailcow\' OR aa.status = \'active\')
       AND (j.endpoint_b_provider = \'mailcow\' OR bb.status = \'active\')'
  );
  $stmt->execute(['owner' => $owner]);
}

calendarSyncRegisterErrorHandlers();
